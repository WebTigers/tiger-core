<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Blog;

use Blog_Model_Taxonomy;
use Blog_Service_Taxonomy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;

/**
 * Blog_Service_Taxonomy — the read-only /api the article editor calls to populate its category/tag
 * pickers. Coverage: the ACL gate (admin+), the vocabulary resolution (defaults to tag when the
 * requested vocab isn't 'category'), and the {id,name,slug} projection.
 */
#[CoversClass(Blog_Service_Taxonomy::class)]
final class TaxonomyServiceTest extends IntegrationTestCase
{
    /** Dispatch the service and return the response object. */
    private function call(string $action, array $params = []): object
    {
        return (new Blog_Service_Taxonomy(['action' => $action] + $params))->getResponse();
    }

    #[Test]
    public function guest_and_plain_user_are_denied_admin_clears(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $this->assertStringContainsString('not_allowed', json_encode($this->call('listTerms')->messages), 'guest denied');

        $this->loginAs('user');
        $this->assertSame(0, (int) $this->call('listTerms')->result, 'plain user denied');

        $this->loginAs('admin');
        $this->assertSame(1, (int) $this->call('listTerms', ['vocabulary' => 'tag'])->result, 'admin allowed');
    }

    #[Test]
    public function list_terms_projects_id_name_slug_for_the_requested_vocabulary(): void
    {
        $this->login('editor', 'org-test', 'admin');
        $tax = new Blog_Model_Taxonomy();
        $tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'Tutorials', 'en', 'org-test');
        $tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'beginner', 'en', 'org-test');

        $cats = $this->call('listTerms', ['vocabulary' => 'category', 'locale' => 'en'])->data['terms'];
        $this->assertCount(1, $cats);
        $this->assertSame('Tutorials', $cats[0]['name']);
        $this->assertSame('tutorials', $cats[0]['slug']);
        $this->assertArrayHasKey('id', $cats[0]);
    }

    #[Test]
    public function list_terms_defaults_to_the_tag_vocabulary_when_unrecognized(): void
    {
        $this->login('editor', 'org-test', 'admin');
        $tax = new Blog_Model_Taxonomy();
        $tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_CATEGORY, 'ACategory', 'en', 'org-test');
        $tax->findOrCreate(Blog_Model_Taxonomy::VOCAB_TAG, 'ATag', 'en', 'org-test');

        // An unrecognized vocabulary collapses to 'tag' (the else branch).
        $names = array_column($this->call('listTerms', ['vocabulary' => 'nonsense'])->data['terms'], 'name');
        $this->assertSame(['ATag'], $names, 'unknown vocabulary → tags');
    }
}
