<?php
/**
 * Tiger_Model_Code — the Tiger Code store (executable PHP + client CSS/JS/HTML).
 *
 * The DB row is the source of truth; Tiger_Code_Runtime compiles active rows into a cached
 * bundle and executes/ships that (never this table per request). This model owns the table:
 * transactional save() with a version snapshot, the compiler's load query, PHP syntax
 * linting, and error-marking for the auto-deactivate rail.
 *
 * SECURITY: `php` rows are server-executed — the service restricts them to the platform
 * scope (`org_id = ''`) and the `code.execute` ACL. `css`/`js`/`html` are client-injected.
 *
 * @api
 */
class Tiger_Model_Code extends Tiger_Model_Table
{
    protected $_name    = 'code';
    protected $_primary = 'code_id';

    const LANG_PHP  = 'php';
    const LANG_JS   = 'js';
    const LANG_CSS  = 'css';
    const LANG_HTML = 'html';

    const LOC_GLOBAL   = 'global';
    const LOC_ADMIN    = 'admin';
    const LOC_FRONTEND = 'frontend';
    const LOC_PAGE     = 'page';

    const STATUS_DRAFT  = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_ERROR  = 'error';

    /** Save (insert/update) + snapshot to code_version, transactionally. Returns code_id. */
    public function save(array $data, $id = null)
    {
        $db = $this->getAdapter();
        $db->beginTransaction();
        try {
            if ($id) {
                unset($data['code_id']);
                $this->update($data, $db->quoteInto('code_id = ?', $id));
            } else {
                $id = $this->insert($data);
            }
            $row = $this->findById($id);
            (new Tiger_Model_CodeVersion())->snapshot($id, [
                'name'         => $row->name,
                'language'     => $row->language,
                'code'         => $row->code,
                'run_location' => $row->run_location,
                'auto_insert'  => $row->auto_insert,
                'priority'     => $row->priority,
                'active'       => $row->active,
                'status'       => $row->status,
            ]);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * The compiler's rebuild query: active rows for a language + run location + org scope,
     * in load order. Hits ix_code_load. Only ever called during a (rare) bundle rebuild.
     */
    public function activeForLoad($language, $location, $orgId = '')
    {
        return $this->fetchAll(
            $this->activeSelect()
                ->where('active = 1')
                ->where('language = ?', (string) $language)
                ->where('run_location = ?', (string) $location)
                ->where('org_id = ?', (string) $orgId)
                ->order(['priority ASC', 'created_at ASC'])
        );
    }

    /**
     * Lint PHP source with `php -l` (out-of-process, never executed). Returns
     * {ok:bool, error:?string}. The safety gate before any PHP row can go active.
     */
    public function lint($code)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tigercode');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'Could not create a temp file to lint.'];
        }
        file_put_contents($tmp, "<?php\n" . $this->normalize($code));

        $bin = (defined('PHP_BINDIR') && @is_executable(PHP_BINDIR . '/php')) ? PHP_BINDIR . '/php' : 'php';
        $out = [];
        $rc  = 1;
        exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        @unlink($tmp);

        if ($rc === 0) {
            return ['ok' => true, 'error' => null];
        }
        $msg = trim(implode("\n", $out));
        $msg = str_replace($tmp, 'your snippet', $msg);   // don't leak the temp path
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Syntax error.'];
    }

    /** Strip a leading `<?php`/`<?`/BOM and a trailing `?>` so stored code is a clean body. */
    public function normalize($code)
    {
        $code = (string) $code;
        $code = preg_replace('/^\xEF\xBB\xBF/', '', $code);   // UTF-8 BOM
        $trim = ltrim($code);
        if (strncmp($trim, '<?php', 5) === 0) {
            $code = substr($trim, 5);
        } elseif (strncmp($trim, '<?=', 3) === 0) {
            $code = 'echo ' . substr($trim, 3);
        } elseif (strncmp($trim, '<?', 2) === 0) {
            $code = substr($trim, 2);
        }
        return preg_replace('/\?>\s*$/', '', $code);
    }

    /** Flag a row inactive with the error that killed it (the auto-deactivate rail). */
    public function markError($id, $msg)
    {
        $this->update(
            ['active' => 0, 'status' => self::STATUS_ERROR, 'last_error' => substr((string) $msg, 0, 2000)],
            $this->getAdapter()->quoteInto('code_id = ?', (string) $id)
        );
    }

    /** Toggle active state (service lints PHP before activating). Clears last_error on activate. */
    public function setActive($id, $active)
    {
        $active = $active ? 1 : 0;
        $this->update([
            'active'     => $active,
            'status'     => $active ? self::STATUS_ACTIVE : self::STATUS_DRAFT,
            'last_error' => $active ? null : new Zend_Db_Expr('last_error'),
        ], $this->getAdapter()->quoteInto('code_id = ?', (string) $id));
    }

    /** Restore a prior version (does NOT auto-reactivate — safer for executable code). */
    public function restoreVersion($id, $version)
    {
        $v = (new Tiger_Model_CodeVersion())->get($id, $version);
        if (!$v) {
            throw new RuntimeException("code_version {$version} not found for {$id}.");
        }
        return $this->save([
            'name'         => $v->name,
            'language'     => $v->language,
            'code'         => $v->code,
            'run_location' => $v->run_location,
            'auto_insert'  => $v->auto_insert,
            'priority'     => $v->priority,
            'status'       => $v->status,
        ], $id);
    }

    /**
     * DataTables data for the admin list: search name/description, filter language, sort,
     * paginate. Query lives here; the service formats + ACL-gates.
     *
     * @return array{total:int,filtered:int,rows:array}
     */
    public function datatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $lang   = (string) ($opts['language'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 25));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $orderCols = [0 => 'name', 1 => 'language', 2 => 'run_location', 3 => 'priority', 4 => 'active', 5 => 'updated_at'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'ASC') ? 'ASC' : 'DESC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'updated_at DESC';

        $scope = function ($sel) use ($lang) {
            $sel->where('deleted = 0');
            if ($lang !== '') { $sel->where('language = ?', $lang); }
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $parts = [];
            foreach (['name', 'description'] as $c) { $parts[] = $db->quoteInto("$c LIKE ?", $like); }
            $sel->where('(' . implode(' OR ', $parts) . ')');
        };

        $totalSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($totalSel);
        $total = (int) $db->fetchOne($totalSel);

        $filteredSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($filteredSel); $searchFn($filteredSel);
        $filtered = (int) $db->fetchOne($filteredSel);

        $rowsSel = $db->select()
            ->from($this->_name, ['code_id', 'name', 'description', 'language', 'run_location', 'priority', 'active', 'status', 'last_error', 'updated_at', 'created_at'])
            ->order(new Zend_Db_Expr($orderSql))
            ->limit($limit, $offset);
        $scope($rowsSel); $searchFn($rowsSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($rowsSel)];
    }
}
