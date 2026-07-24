<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace {
    // ---- Global fixtures: a service + a form the generator reflects ------------------------------
    // (Global namespace so their `Module_Service_X` / `Module_Form_X` names parse the way the real
    //  gateway resolves them. The service is NEVER instantiated — the generator only reflects it —
    //  so its dispatch-in-constructor never runs.)

    if (!class_exists('Openapifix_Form_Widget')) {
        /** A fixture form: text (one required, one labelled), checkbox, password, and a `csrf` field
         *  that must be dropped from the schema. CSRF disabled so no session/hash element is created. */
        class Openapifix_Form_Widget extends \Tiger_Form
        {
            protected function csrf(): bool { return false; }

            protected function elements(): array
            {
                return [
                    ['text',     'title',  ['required' => true, 'label' => 'The title']],
                    ['text',     'note',   []],
                    ['checkbox', 'active', []],
                    ['password', 'secret', []],
                    ['text',     'csrf',   []],   // must be excluded by the _csrf/csrf guard
                ];
            }
        }
    }

    if (!class_exists('Openapifix_Service_Widget')) {
        /** A fixture service. `create` carries a summary + description + @apiRequest; `plainlist` has no
         *  docblock (fallback summary + generic body); `_helper` and the static are skipped. */
        class Openapifix_Service_Widget extends \Tiger_Service_Service
        {
            /**
             * Create a widget.
             *
             * A longer description spanning
             * multiple lines.
             *
             * @apiRequest Openapifix_Form_Widget
             */
            public function create(array $params) {}

            public function plainlist(array $params) {}

            public function _helper(array $params) {}

            public static function bulk(array $params) {}
        }
    }

    // Elements whose lowercased class names contain the tokens _elementSchema keys on.
    if (!class_exists('Openapifix_El_Emailish'))  { class Openapifix_El_Emailish  extends \Zend_Form_Element_Text {} }
    if (!class_exists('Openapifix_El_Numberish')) { class Openapifix_El_Numberish extends \Zend_Form_Element_Text {} }
    if (!class_exists('Openapifix_El_Spinnerish')){ class Openapifix_El_Spinnerish extends \Zend_Form_Element_Text {} }
}

namespace Tiger\Tests\Unit\OpenApi {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Test;
    use Tiger\Tests\Support\UnitTestCase;
    use Tiger_OpenApi_Generator;

    /**
     * Tiger_OpenApi_Generator — the "reflect the /api surface into OpenAPI 3" generator (WEBSERVICES.md
     * §9). Pure reflection, no boot: we feed it a fixture service + form and assert the emitted document
     * — operations, the form→schema mapping, the shared response envelope, module tags, docblock
     * summaries, and the source-scan discovery.
     */
    #[CoversClass(Tiger_OpenApi_Generator::class)]
    final class GeneratorTest extends UnitTestCase
    {
        private Tiger_OpenApi_Generator $gen;

        protected function setUp(): void
        {
            parent::setUp();
            $this->gen = new Tiger_OpenApi_Generator();
        }

        #[Test]
        public function the_document_has_the_openapi_shell_and_the_response_envelope_component(): void
        {
            $doc = $this->gen->generate([]);

            $this->assertSame('3.0.3', $doc['openapi']);
            $this->assertSame('Tiger API', $doc['info']['title']);
            $this->assertSame([['url' => '/']], $doc['servers']);
            $this->assertArrayHasKey('TigerResponse', $doc['components']['schemas']);

            $env = $doc['components']['schemas']['TigerResponse'];
            $this->assertSame(['result'], $env['required']);
            $this->assertSame([0, 1], $env['properties']['result']['enum']);
            $this->assertSame(['success', 'error', 'alert', 'info'], $env['properties']['messages']['items']['properties']['class']['enum']);
        }

        #[Test]
        public function info_overrides_merge_over_the_defaults(): void
        {
            $gen = new Tiger_OpenApi_Generator(['title' => 'My API', 'version' => '9.9.9']);
            $doc = $gen->generate([]);
            $this->assertSame('My API', $doc['info']['title']);
            $this->assertSame('9.9.9', $doc['info']['version']);
            $this->assertNotEmpty($doc['info']['description'], 'the default description fills the gap');
        }

        #[Test]
        public function a_service_becomes_operations_tagged_by_module(): void
        {
            $doc = $this->gen->generate(['Openapifix_Service_Widget']);

            // One path per public, own, non-underscore, instance method — static + `_helper` excluded.
            $this->assertArrayHasKey('/api/openapifix/widget/create', $doc['paths']);
            $this->assertArrayHasKey('/api/openapifix/widget/plainlist', $doc['paths']);
            $this->assertArrayNotHasKey('/api/openapifix/widget/bulk', $doc['paths'], 'a static method is not an operation');
            $this->assertArrayNotHasKey('/api/openapifix/widget/_helper', $doc['paths']);

            $op = $doc['paths']['/api/openapifix/widget/create']['post'];
            $this->assertSame('openapifix.widget.create', $op['operationId']);
            $this->assertSame(['openapifix'], $op['tags']);
            $this->assertSame('Create a widget.', $op['summary']);
            $this->assertStringContainsString('longer description', $op['description']);
            $this->assertStringContainsString('POST /api', $op['description']);
            $this->assertSame('#/components/schemas/TigerResponse',
                $op['responses']['200']['content']['application/json']['schema']['$ref']);

            $this->assertSame([['name' => 'openapifix']], $doc['tags']);
        }

        #[Test]
        public function a_method_without_a_docblock_gets_a_fallback_summary_and_a_generic_body(): void
        {
            $doc = $this->gen->generate(['Openapifix_Service_Widget']);
            $op  = $doc['paths']['/api/openapifix/widget/plainlist']['post'];

            $this->assertSame('widget · plainlist', $op['summary'], 'no docblock → service · method');
            $schema = $op['requestBody']['content']['application/x-www-form-urlencoded']['schema'];
            $this->assertTrue($schema['additionalProperties'], 'no @apiRequest form → a generic object body');
        }

        #[Test]
        public function the_request_body_schema_is_built_from_the_apiRequest_form(): void
        {
            $doc    = $this->gen->generate(['Openapifix_Service_Widget']);
            $schema = $doc['paths']['/api/openapifix/widget/create']['post']
                          ['requestBody']['content']['application/x-www-form-urlencoded']['schema'];

            $this->assertSame('object', $schema['type']);
            $props = $schema['properties'];

            $this->assertArrayNotHasKey('csrf', $props, 'a csrf field is dropped from the schema');
            $this->assertSame('string',  $props['title']['type']);
            $this->assertSame('The title', $props['title']['description'], 'the element label becomes the description');
            $this->assertSame('boolean', $props['active']['type'], 'a checkbox → boolean');
            $this->assertSame('string',  $props['secret']['type']);
            $this->assertSame('password', $props['secret']['format'], 'a password element → format:password');

            $this->assertContains('title', $schema['required']);
            $this->assertNotContains('note', $schema['required']);
        }

        #[Test]
        public function element_schema_maps_email_number_and_spinner_by_class_name(): void
        {
            $m = new \ReflectionMethod(Tiger_OpenApi_Generator::class, '_elementSchema');

            $email = $m->invoke($this->gen, new \Openapifix_El_Emailish('e'));
            $this->assertSame(['type' => 'string', 'format' => 'email'], $email);

            $number = $m->invoke($this->gen, new \Openapifix_El_Numberish('n'));
            $this->assertSame(['type' => 'number'], $number);

            $spinner = $m->invoke($this->gen, new \Openapifix_El_Spinnerish('s'));
            $this->assertSame(['type' => 'number'], $spinner);
        }

        #[Test]
        public function a_non_service_class_is_skipped(): void
        {
            // A real class that does NOT extend Tiger_Service_Service is ignored, not fatal.
            $doc = $this->gen->generate(['Zend_Config', 'DoesNotExist_Service_Nope']);
            $this->assertSame([], $doc['paths']);
            $this->assertSame([], $doc['tags']);
        }

        #[Test]
        public function discover_scans_source_for_service_class_names(): void
        {
            $dir = sys_get_temp_dir() . '/tiger_oa_' . bin2hex(random_bytes(4));
            @mkdir($dir, 0775, true);
            file_put_contents($dir . '/Widget.php', "<?php\nclass Sample_Service_Widget extends Tiger_Service_Service {}\n");
            file_put_contents($dir . '/Notes.php',  "<?php\nclass Sample_Service_Notes extends Tiger_Service_Service {}\n");
            file_put_contents($dir . '/plain.php',  "<?php\nclass Sample_Model_Thing {}\n");

            $found = $this->gen->discover([$dir]);
            sort($found);
            $this->assertSame(['Sample_Service_Notes', 'Sample_Service_Widget'], $found);

            array_map('unlink', glob($dir . '/*'));
            @rmdir($dir);
        }
    }
}
