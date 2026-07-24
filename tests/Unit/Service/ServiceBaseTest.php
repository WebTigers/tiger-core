<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Service {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Test;
    use Tiger\Tests\Support\UnitTestCase;
    use Tiger_Log;
    use Tiger_Service_Service;
    use Zend_Config;
    use Zend_Registry;

    /**
     * Tiger_Service_Service — the abstract /api base. Covered here with a concrete probe subclass: message
     * DISPATCH (the named action runs; an unknown / underscore-guarded / non-existent action fails cleanly;
     * a thrown action becomes a safe error envelope), the base64 WAF shim (_decodeB64), the DataTables
     * helpers (_dtParams normalization + clamping, _dtResponse envelope), and _formErrors (the CSRF special
     * case vs. ordinary field errors). No DB / no network — the base's own surface only.
     */
    #[CoversClass(Tiger_Service_Service::class)]
    final class ServiceBaseTest extends UnitTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            // The dispatch-exception path logs via Tiger_Log; route it to a null writer so the diagnostic
            // line doesn't print (PHPUnit would flag the test "risky").
            Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['log' => ['writer' => 'null']]]));
            Tiger_Log::reset();
        }

        protected function tearDown(): void
        {
            Tiger_Log::reset();
            parent::tearDown();
        }

        // ---- dispatch ------------------------------------------------------

        #[Test]
        public function a_named_action_runs_and_receives_the_whole_message(): void
        {
            $r = (new \ServiceProbe(['action' => 'ping', 'x' => 'hello']))->getResponse();
            $this->assertSame(1, $r->result);
            $this->assertSame('hello', $r->data['pong']);
        }

        #[Test]
        public function no_action_leaves_an_empty_untouched_response(): void
        {
            $r = (new \ServiceProbe([]))->getResponse();
            $this->assertSame(0, $r->result, 'a fresh ResponseObject defaults to result=0');
            $this->assertSame([], $r->messages, 'nothing was dispatched, so no message was emitted');
        }

        #[Test]
        public function an_unknown_action_is_a_clean_invalid_action_error(): void
        {
            $r = (new \ServiceProbe(['action' => 'doesNotExist']))->getResponse();
            $this->assertSame(0, $r->result);
            $this->assertSame('core.api.error.invalid_action', $r->messages[0]->message);
        }

        #[Test]
        public function an_underscore_prefixed_action_cannot_reach_internal_helpers(): void
        {
            $r = (new \ServiceProbe(['action' => '_decodeB64']))->getResponse();
            $this->assertSame('core.api.error.invalid_action', $r->messages[0]->message, 'protected helpers are unreachable by name');
        }

        #[Test]
        public function a_throwing_action_becomes_a_safe_error_envelope(): void
        {
            // APPLICATION_ENV is 'testing' in the harness, so the real message surfaces (prod would mask it).
            $r = (new \ServiceProbe(['action' => 'boom']))->getResponse();
            $this->assertSame(0, $r->result);
            $this->assertSame('kaboom', $r->messages[0]->message);
        }

        // ---- base64 WAF shim ----------------------------------------------

        #[Test]
        public function decode_b64_transparently_expands_suffixed_fields_before_dispatch(): void
        {
            $r = (new \ServiceProbe(['action' => 'ping', 'x_b64' => base64_encode('decoded!')]))->getResponse();
            $this->assertSame('decoded!', $r->data['pong'], 'the *_b64 field is decoded into its base name');
        }

        // ---- DataTables helpers -------------------------------------------

        #[Test]
        public function dt_params_applies_sane_defaults(): void
        {
            $p = (new \ServiceProbe([]))->dtParams([]);
            $this->assertSame(0, $p['draw']);
            $this->assertSame(0, $p['start']);
            $this->assertSame(25, $p['length'], 'default page size');
            $this->assertSame('', $p['search']);
            $this->assertSame([], $p['order']);
        }

        #[Test]
        public function dt_params_clamps_length_and_treats_minus_one_as_the_max(): void
        {
            $probe = new \ServiceProbe([]);
            $this->assertSame(100, $probe->dtParams(['length' => -1])['length'], '-1 (show all) is capped at maxLength');
            $this->assertSame(100, $probe->dtParams(['length' => 9999])['length'], 'an over-large page is clamped');
            $this->assertSame(1, $probe->dtParams(['length' => 0])['length'], 'length is floored at 1');
            $this->assertSame(10, $probe->dtParams(['length' => 500], 10)['length'], 'a lower maxLength wins');
        }

        #[Test]
        public function dt_params_reads_search_as_string_or_datatables_object_and_parses_order(): void
        {
            $probe = new \ServiceProbe([]);
            $this->assertSame('tiger', $probe->dtParams(['search' => '  tiger  '])['search'], 'a bare string is trimmed');
            $this->assertSame('cub', $probe->dtParams(['search' => ['value' => 'cub']])['search'], 'the DataTables {value} shape is unwrapped');

            $order = $probe->dtParams(['order' => [
                ['column' => '2', 'dir' => 'DESC'],
                ['column' => '0', 'dir' => 'weird'],   // anything but desc => ASC
                ['nope' => 1],                          // malformed => skipped
            ]])['order'];
            $this->assertSame([['column' => 2, 'dir' => 'DESC'], ['column' => 0, 'dir' => 'ASC']], $order);
        }

        #[Test]
        public function dt_params_floors_negative_draw_and_start(): void
        {
            $p = (new \ServiceProbe([]))->dtParams(['draw' => -5, 'start' => -10]);
            $this->assertSame(0, $p['draw']);
            $this->assertSame(0, $p['start']);
        }

        #[Test]
        public function dt_response_emits_the_datatables_envelope_inside_the_success_shape(): void
        {
            $r = (new \ServiceProbe([]))->dtResponse(3, 42, 7, [['id' => 1]]);
            $this->assertSame(1, $r->result);
            $this->assertSame(3, $r->data['draw']);
            $this->assertSame(42, $r->data['recordsTotal']);
            $this->assertSame(7, $r->data['recordsFiltered']);
            $this->assertSame([['id' => 1]], $r->data['data']);
        }

        // ---- form errors ---------------------------------------------------

        #[Test]
        public function form_errors_surfaces_ordinary_field_errors(): void
        {
            $form = new \FakeMessagesForm(['amount' => ['isEmpty' => 'Value is required']]);
            $r = (new \ServiceProbe([]))->formErrors($form);
            $this->assertSame(0, $r->result);
            $this->assertSame(['amount' => ['isEmpty' => 'Value is required']], $r->form);
            $this->assertSame('core.api.error.form', $r->messages[0]->message);
        }

        #[Test]
        public function form_errors_treats_a_csrf_failure_specially(): void
        {
            $form = new \FakeMessagesForm(['_csrf' => ['badHash' => 'stale token']]);
            $r = (new \ServiceProbe([]))->formErrors($form);
            $this->assertSame(0, $r->result);
            $this->assertNull($r->form, 'a CSRF failure is not surfaced as a field error');
            $this->assertSame('core.api.error.csrf', $r->messages[0]->message);
        }
    }
}

namespace {

    /** Concrete service exposing the protected base helpers + a couple of dispatchable actions. */
    class ServiceProbe extends Tiger_Service_Service
    {
        public function ping(array $params): void { $this->_success(['pong' => $params['x'] ?? null]); }
        public function boom(array $params): void { throw new RuntimeException('kaboom'); }

        public function dtParams(array $p, int $max = 100): array { return $this->_dtParams($p, $max); }
        public function dtResponse(int $draw, int $total, int $filtered, array $data): object
        {
            $this->_dtResponse($draw, $total, $filtered, $data);
            return $this->getResponse();
        }
        public function formErrors(Zend_Form $form): object
        {
            $this->_formErrors($form);
            return $this->getResponse();
        }
    }

    /** A Zend_Form stand-in with a canned getMessages() payload (no elements/session needed). */
    class FakeMessagesForm extends Zend_Form
    {
        private array $canned;
        public function __construct(array $messages)
        {
            $this->canned = $messages;
            parent::__construct();
        }
        public function getMessages($name = null, $suppressArrayNotation = false): array
        {
            return $this->canned;
        }
    }
}
