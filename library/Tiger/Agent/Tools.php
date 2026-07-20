<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Tools — build the model's tool catalog + system prompt from the LIVE, role-
 * filtered /api surface (TIGERAGENT.md §2, §5a).
 *
 * The agent's tools are not a hand-kept list — they ARE the platform's `/api` services, the
 * exact same ones a human of this role can reach, discovered by reflection and filtered
 * through the ACL. So the day a module is installed, its services become agent-callable for
 * free; the day a user's role changes, the agent's reach changes with it. Nothing to
 * maintain, nothing to drift.
 *
 * @api
 */
class Tiger_Agent_Tools
{
    /** Keep the catalog compact so it doesn't blow the context budget on big installs. */
    const MAX_OPERATIONS = 160;

    /**
     * The role-filtered catalog of callable operations, grouped by module:
     * `['cms' => [['service'=>'page','method'=>'save','summary'=>'…'], …], …]`.
     *
     * @param  string $role the acting role
     * @return array
     */
    public static function catalog($role)
    {
        $acl = Zend_Registry::isRegistered('Zend_Acl') ? Zend_Registry::get('Zend_Acl') : null;
        if (!$acl) {
            return [];
        }

        $gen     = new Tiger_OpenApi_Generator();
        $classes = $gen->discover($gen->moduleServiceDirs());
        $doc     = $gen->generate($classes);

        $catalog = [];
        $count   = 0;
        foreach ((array) ($doc['paths'] ?? []) as $path => $ops) {
            if ($count >= self::MAX_OPERATIONS) {
                break;
            }
            // /api/{module}/{service}/{method}
            $parts = explode('/', trim($path, '/'));
            if (count($parts) !== 4 || $parts[0] !== 'api') {
                continue;
            }
            [, $module, $service, $method] = $parts;

            // The agent never calls its OWN plumbing (send/resume/approve/…) — exclude it so the
            // model can't recurse into itself or get confused by its own service surface.
            if ($module === 'agent') {
                continue;
            }

            $class = ucfirst($module) . '_Service_' . ucfirst($service);
            if (!$acl->has($class) || !$acl->isAllowed($role, $class, $method)) {
                continue;   // the role can't call it → the model never sees it
            }
            $summary = (string) ($ops['post']['summary'] ?? '');
            $catalog[$module][] = ['service' => $service, 'method' => $method, 'summary' => $summary];
            $count++;
        }
        ksort($catalog);
        return $catalog;
    }

    /**
     * Assemble the full system prompt: who the agent is, the response contract, the read tools
     * (Scout) + the loop, the capability tiers this role unlocks, the current auto-mode, and the
     * callable catalog.
     *
     * @param  string $role         the acting role
     * @param  array  $capabilities the Tiger_Agent::capabilities() map
     * @param  array  $context      optional request context (path, etc.)
     * @param  string $mode         ask | auto | yolo
     * @return string
     */
    public static function systemPrompt($role, array $capabilities, array $context = [], $mode = 'ask')
    {
        $catalog = self::catalog($role);
        $tools   = self::renderCatalog($catalog);
        $path    = (string) ($context['path'] ?? '');

        $caps = [];
        if (!empty($capabilities['inventory'])) { $caps[] = 'inspect the system (inventory)'; }
        if (!empty($capabilities['read']))      { $caps[] = 'read the file tree, files, and search (Scout)'; }
        if (!empty($capabilities['dom']))       { $caps[] = 'read + rewrite editable content on the page'; }
        if (!empty($capabilities['api']))       { $caps[] = 'call /api services (bounded by your ACL)'; }
        if (!empty($capabilities['code']))      { $caps[] = 'author executable PHP snippets (Code Area)'; }
        if (!empty($capabilities['file']))      { $caps[] = 'write files into public app modules'; }
        if (!empty($capabilities['module']))    { $caps[] = 'scaffold new modules'; }
        $capsLine = $caps ? implode('; ', $caps) : 'answer questions and guide the user';

        // DOM tools — advertised only when the current page actually exposes targets (the browser
        // sends the list in the context each turn). The model reads/writes them by name.
        $domBlock = '';
        $targets  = (isset($context['targets']) && is_array($context['targets'])) ? $context['targets'] : [];
        if (!empty($capabilities['dom']) && $targets) {
            $rows = [];
            foreach ($targets as $t) {
                if (!is_array($t) || empty($t['name'])) { continue; }
                $rows[] = '  - ' . $t['name'] . ' [' . ($t['kind'] ?? 'text') . '] ' . ($t['label'] ?? '');
            }
            if ($rows) {
                $list = implode("\n", $rows);
                $domBlock = <<<DOM


THE CURRENT PAGE HAS EDITABLE TARGETS you can read + rewrite in place (an article body, a title, a
code editor…). Read one before rewriting it, then write the improved content back:
- Read a target:  { "type":"dom.read",  "target":"<name>", "reason":"see the current text" }
- Write a target: { "type":"dom.write", "target":"<name>", "value":"<new text or HTML>", "reason":"..." }
For a target of kind "html" the value IS HTML — that's expected, it's the user's own editor; "text" is
plain text; "code" is source. Set done:false after a dom.read so you receive the content and continue.
TARGETS on this page:
$list
DOM;
            }
        }

        // The read-tool block — each line shown only at the tier that can run it (inventory=admin,
        // the rest=superadmin), so the model is never advertised a tool it would be denied.
        $readLines = [];
        if (!empty($capabilities['inventory'])) {
            $readLines[] = '- Map the system:   { "type":"read.inventory", "reason":"see modules (which have guides), snippets, theme, roots" }';
        }
        if (!empty($capabilities['read'])) {
            $readLines[] = '- Read a GUIDE:     { "type":"read.guide", "module":"cms", "reason":"how this module works" }  (omit "module" for the platform conventions)';
            $readLines[] = '- List a directory: { "type":"read.tree", "path":"themes/puma/assets/js", "reason":"..." }';
            $readLines[] = '- Read a file:      { "type":"read.file", "path":"vendor/webtigers/tiger-core/themes/puma/assets/js/tiger.button.js", "reason":"match house style" }';
            $readLines[] = '- Search:           { "type":"read.grep", "query":"show password", "reason":"check it doesn\'t already exist" }';
        }
        $readBlock = '';
        if ($readLines) {
            $readList = implode("\n", $readLines);
            $readBlock = <<<READ

LOOK BEFORE YOU LEAP — you have READ tools (they run instantly, no approval; results come back to
you and you continue). USE THEM before writing so you land changes in the right place, match Tiger's
patterns, and never duplicate something that exists:
$readList

THE LOOP: to gather context, return read actions with done:false — you'll get the results and can
issue more, or then propose the change. Read as many times as you need before acting.
READ;
        }

        $modeLine = [
            'ask'  => 'ASK — every change you make is shown to the user for approval before it runs.',
            'auto' => 'AUTO — routine /api changes run automatically; executable code, file writes, and module scaffolds still ask for approval.',
            'yolo' => 'YOLO — everything you are permitted to do runs automatically without asking. Be careful, explain what you did, and keep going until the task is truly done.',
        ][$mode] ?? 'ASK — changes are shown for approval.';

        // Accessibility — a genuinely loved capability: audit with TigerAlly, then FIX the markup in a
        // public app module's view files. Advertised when the role can write files (the "fix" tier);
        // scanning alone is always in the catalog below.
        $a11yBlock = !empty($capabilities['file']) ? <<<ALLY


ACCESSIBILITY (TigerAlly) — you can AUDIT it and FIX it, which users love:
- Audit a module:  { "type":"api", "module":"ally", "service":"scan", "method":"scanModule", "params":{"module":"<app-module>"}, "reason":"find a11y gaps" }  — auto-runs; returns findings grouped by FILE (with the exact path to fix). Also ally/scan/scan (a CMS page or pasted HTML) and ally/scan/scanAll (every page).
- Fix it: read the reported file, then a "file" write that adds only the missing alt / aria-label / <label> / heading fix — minimal, semantic, no restyling. Re-run the scan to confirm 0 errors.
- You can only fix PUBLIC app modules (application/modules); core & theme a11y is the Tiger team's. Offer this whenever accessibility, ADA, WCAG, alt text, or screen readers come up.
ALLY : '';

        return <<<PROMPT
You are TigerAgent, the AI built into TIGER — a modular, multi-tenant CMS/SaaS platform on modern
PHP (8.1–8.5). Think WordPress-class capability (pages, blog, media, themes, installable modules)
but built on a clean /api service layer, deny-by-default ACL, and runtime theming — no legacy cruft.
You are running INSIDE a live install, acting on behalf of the signed-in user, whose role is
"{$role}". You can never do more than that user could do by hand — every action you propose is
re-checked against their permissions before it runs.

TIGER HAS HOUSE CONVENTIONS you MUST follow, and modules document how to work on them. Don't guess:
use read.guide (below) for the platform conventions (AGENTS.md) and a module's own guide BEFORE you
write or change its code — matching Tiger's patterns matters more than clever code.

WHAT YOU CAN DO AS THIS USER: {$capsLine}.
CURRENT MODE: {$modeLine}
The user is currently on the page: {$path}

HOW TO REPLY — THIS IS STRICT:
Reply with EXACTLY ONE JSON object and nothing else (no prose outside it, no code fence). Shape:

{
  "say": "<what to tell the user, in markdown>",
  "actions": [ <zero or more action objects, see below> ],
  "navigate": "<an in-app path to send the user to, or omit>",
  "done": <true if the request is fully handled, false if you expect to continue after actions run>
}

WRITE ACTIONS (only use types your capabilities allow; every action needs a short "reason"):
- Call a service:   { "type":"api", "module":"cms", "service":"page", "method":"save",
                      "params": { ... }, "reason":"..." }
- Write module file:{ "type":"file", "path":"modules/<mod>/views/scripts/...", "contents":"...", "reason":"..." }
- Executable PHP:   { "type":"code", "name":"...", "language":"php", "code":"<?php ...", "reason":"..." }
- Scaffold module:  { "type":"module", "name":"<slug>", "reason":"..." }
{$readBlock}{$domBlock}{$a11yBlock}

RULES:
- Before writing or changing code in a module, read.guide it (and read.guide the platform
  conventions) — Tiger has specific patterns you must match; matching them is the job.
- Prefer "api" actions over files — the services already validate + secure the write. Only write
  files/code when a service can't do the job.
- Client-side JS/CSS belongs in a Code-Area snippet ("type":"code", language js/css), NOT a loose
  theme file — run read.inventory if unsure where something lives.
- File writes only ever land inside application/modules. You cannot touch core, the framework, or
  yourself — don't try.
- If you're missing information, ask in "say" with an empty actions list and done:false.
- Keep "say" concise and friendly. Never invent a service/method that isn't in the catalog below.

CALLABLE /api CATALOG (module/service/method — summary), already filtered to your permissions:
{$tools}
PROMPT;
    }

    /**
     * Render the catalog as compact prompt lines.
     *
     * @param  array $catalog the grouped catalog
     * @return string
     */
    protected static function renderCatalog(array $catalog)
    {
        if (!$catalog) {
            return '(no callable services for this role)';
        }
        $lines = [];
        foreach ($catalog as $module => $ops) {
            foreach ($ops as $op) {
                $lines[] = "  {$module}/{$op['service']}/{$op['method']}"
                    . ($op['summary'] !== '' ? ' — ' . $op['summary'] : '');
            }
        }
        return implode("\n", $lines);
    }
}
