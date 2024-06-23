<?php

use PHPUnit\Framework\TestCase;

class WP_Block_Markup_Processor_Tests extends TestCase
{

    /**
     * 
     * @dataProvider provider_test_finds_block_openers
     */
    public function test_finds_block_openers($markup, $block_name, $block_attributes)
    {
        $p = new WP_Block_Markup_Processor($markup);
        $p->next_token();
        $this->assertEquals('#block-comment', $p->get_token_type(), 'Failed to identify the block comment');
        $this->assertEquals($block_name, $p->get_block_name(), 'Failed to identify the block name');
        $this->assertEquals($block_attributes, $p->get_block_attributes(), 'Failed to identify the block attributes');
    }
    
    static public function provider_test_finds_block_openers()
    {
        return [
            'Opener without attributes' => ['<!-- wp:paragraph -->', 'wp:paragraph', null],
            'Opener without the trailing whitespace' => ['<!--wp:paragraph-->', 'wp:paragraph', null],
            'Opener with a lot of trailing whitespace' => ['<!--    wp:paragraph          -->', 'wp:paragraph', null],
            'Opener with attributes' => ['<!-- wp:paragraph {"class": "wp-bold"} -->', 'wp:paragraph', ['class' => 'wp-bold']],
            'Opener with empty attributes' => ['<!-- wp:paragraph {} -->', 'wp:paragraph', []],
            'Opener with lots of whitespace around attributes' => [
                '<!-- wp:paragraph   {    "class":   "wp-bold"  }   -->',
                'wp:paragraph',
                [ 'class'=> 'wp-bold']
            ],
            'Opener with object and array attributes' => [
                '<!-- wp:code { "meta": { "language": "php", "highlightedLines": [14, 22] }, "class": "dark" } -->',
                'wp:code',
                [ 'meta' => [ 'language' => 'php', 'highlightedLines' => [14, 22] ], 'class' => 'dark' ]
            ],
        ];
    }

    /**
     * 
     * @dataProvider provider_test_finds_block_closers
     */
    public function test_find_block_closers($markup, $block_name)
    {
        $p = new WP_Block_Markup_Processor($markup);
        $p->next_token();
        $this->assertEquals('#block-comment', $p->get_token_type(), 'Failed to identify the block comment');
        $this->assertEquals($block_name, $p->get_block_name(), 'Failed to identify the block name');
        $this->assertTrue($p->is_block_closer(), 'Failed to identify the block closer status');   
    }

    static public function provider_test_finds_block_closers()
    {
        return [
            'Closer without attributes' => ['<!-- /wp:paragraph -->', 'wp:paragraph'],
            'Closer without the trailing whitespace' => ['<!--/wp:paragraph-->', 'wp:paragraph'],
            'Closer with a lot of trailing whitespace' => ['<!--    /wp:paragraph          -->', 'wp:paragraph'],
        ];        
    }

    /**
     * 
     * @dataProvider provider_test_treat_invalid_block_openers_as_comments
     */
    public function test_treat_invalid_block_openers_as_comments($markup)
    {
        $p = new WP_Block_Markup_Processor($markup);
        $p->next_token();
        $this->assertEquals('#comment', $p->get_token_type(), 'Failed to identify the comment');
        $this->assertFalse($p->get_block_name(), 'The block name wasn\'t false');
        $this->assertFalse($p->get_block_attributes(), 'The block attributes weren\'t false');
    }
    
    static public function provider_test_treat_invalid_block_openers_as_comments()
    {
        return [
            'Opener with a line break before whitespace' => ["<!-- \nwp:paragraph -->",],
            'Block name including !' => ['<!-- wp:pa!ragraph -->',],
            'Block name including a whitespace' => ['<!-- wp: paragraph -->',],
            'No namespace in the block name' => ['<!-- paragraph -->',],
            'Non-object attributes' => ['<!-- wp:paragraph "attrs" -->',],
            'Invalid JSON as attributes – Double }} ' => ['<!-- wp:paragraph {"class":"wp-block"}} -->',],
        ];
    }
    
    /**
     * 
     * @dataProvider provider_test_treat_invalid_block_closers_as_comments
     */
    public function test_treat_invalid_block_closers_as_comments($markup)
    {
        $p = new WP_Block_Markup_Processor($markup);
        $p->next_token();
        $this->assertEquals('#comment', $p->get_token_type(), 'Failed to identify the comment');
        $this->assertFalse($p->get_block_name(), 'The block name wasn\'t false');
        $this->assertFalse($p->get_block_attributes(), 'The block attributes weren\'t false');
    }
    
    static public function provider_test_treat_invalid_block_closers_as_comments()
    {
        return [
            'Closer with a line break before whitespace' => ["<!-- \n/wp:paragraph -->",],
            'Closer with attributes' => ['<!-- /wp:paragraph {"class": "block"} -->',],
            'Closer with solidus at the end (before whitespace)' => ['<!-- wp:paragraph/ -->',],
            'Closer with solidus at the end (after whitespace)' => ['<!-- wp:paragraph /-->',],
        ];
    }

    

}
