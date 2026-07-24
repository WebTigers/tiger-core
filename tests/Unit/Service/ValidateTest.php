<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Service {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Test;
    use Tiger\Tests\Support\UnitTestCase;
    use Tiger_Service_Validate;

    /**
     * Tiger_Service_Validate — convenience validation over /api. It resolves `<Module>_Form_<Form>` from the
     * message, runs that element's REAL validators, and returns `{valid, message}` in a success envelope. The
     * load-bearing behaviors: an unknown/non-Tiger_Form target is treated as valid (never block the UI), the
     * class name is sanitized before use, and a real form's validator decides the field. A CSRF-less fixture
     * form (`Vfix_Form_Sample`, defined below in the global namespace so the built class name resolves) makes
     * the whole path a pure unit test — no session, no DB.
     */
    #[CoversClass(Tiger_Service_Validate::class)]
    final class ValidateTest extends UnitTestCase
    {
        private function data(array $message): array
        {
            return (array) (new Tiger_Service_Validate($message + ['action' => 'field']))->getResponse()->data;
        }

        #[Test]
        public function an_unknown_form_is_reported_valid(): void
        {
            $d = $this->data(['form_module' => 'nope', 'form' => 'missing', 'field' => 'x', 'value' => 'y']);
            $this->assertTrue($d['valid'], 'a non-existent form never blocks the UI');
            $this->assertSame('', $d['message']);
        }

        #[Test]
        public function an_empty_module_or_form_is_reported_valid(): void
        {
            $this->assertTrue($this->data(['form_module' => '', 'form' => 'sample', 'field' => 'x'])['valid']);
            $this->assertTrue($this->data(['form_module' => 'vfix', 'form' => '', 'field' => 'x'])['valid']);
        }

        #[Test]
        public function a_real_form_reports_an_invalid_field(): void
        {
            $d = $this->data(['form_module' => 'vfix', 'form' => 'sample', 'field' => 'amount', 'value' => 'not-a-number']);
            $this->assertFalse($d['valid'], 'a non-numeric amount fails the Digits validator');
            $this->assertNotSame('', $d['message'], 'the first validator message is surfaced inline');
        }

        #[Test]
        public function a_real_form_reports_a_valid_field(): void
        {
            $d = $this->data(['form_module' => 'vfix', 'form' => 'sample', 'field' => 'amount', 'value' => '4200']);
            $this->assertTrue($d['valid']);
            $this->assertSame('', $d['message']);
        }

        #[Test]
        public function dirty_form_names_are_sanitized_to_alpha_before_resolution(): void
        {
            // The dots/digits are stripped, so "v.fix9"/"sam9ple" still resolves to Vfix_Form_Sample.
            $d = $this->data(['form_module' => 'v.fix9', 'form' => 'sam9ple', 'field' => 'amount', 'value' => 'x']);
            $this->assertFalse($d['valid'], 'sanitized segments reach the real fixture form');
        }
    }
}

namespace {

    /** A CSRF-less fixture form so convenience validation runs with no session/DB. */
    class Vfix_Form_Sample extends Tiger_Form
    {
        protected function csrf(): bool
        {
            return false;
        }

        protected function elements(): array
        {
            return [
                ['text', 'amount', [
                    'filters'    => ['StringTrim'],
                    'validators' => [['Digits']],
                ]],
            ];
        }
    }
}
