<?php

use PHPUnit\Framework\TestCase;

//$block_markup = file_get_contents( __DIR__ . '/../married.html' );
//
//$p = new URL_Rewriter( $block_markup );
//$p->next_url();

// for ($i = 0; $i < 15; $i++) {
//     $p->next_token();
//     if($p->get_token_type() === '#block-comment') {
//         $updated_attrs = map_block_attributes($p->get_block_attributes(), function($key, $value) {
//             if($key === 'url') {
//                 return 'https://example.com';
//             }
//             return $value;
//         });
//         $p->set_block_attributes($updated_attrs);
//         echo substr($p->get_updated_html(), 0, 100) . "\n";
//         die();
//     }
// }

//private function map_block_attributes( array $attributes, $mapper ) {
//	$new_attributes = array();
//	foreach ( $attributes as $key => $value ) {
//		if ( is_array( $value ) ) {
//			$new_attributes[ $key ] = map_block_attributes( $value, $mapper );
//		} else {
//			$new_attributes[ $key ] = $mapper( $key, $value );
//		}
//	}
//
//	return $new_attributes;
//}


class WP_Block_Markup_Url_Processor_Tests extends TestCase
{

    /**
     *
     * @dataProvider provider_test_finds_next_url
     */
    public function test_next_url_finds_the_url($url, $markup)
    {
        $p = new WP_Block_Markup_Url_Processor($markup);
        $this->assertTrue($p->next_url(), 'Failed to find the URL in the markup.');
		$this->assertEquals($url, $p->get_url(), 'Found a URL in the markup, but it wasn\'t the expected one.');
    }

    static public function provider_test_finds_next_url()
    {
        return [
            'In the <a> tag' => ['https://wordpress.org', '<a href="https://wordpress.org">'],
            'In the first block attribute, when it contains just the URL' => [
	            'https://mysite.com/wp-content/image.png',
	            '<!-- wp:image {"src": "https://mysite.com/wp-content/image.png"} -->'
            ],
            'In the second block attribute, when it contains just the URL' => [
	            'https://mysite.com/wp-content/image.png',
	            '<!-- wp:image {"class": "wp-bold", "src": "https://mysite.com/wp-content/image.png"} -->'
            ],
        ];
    }

	public function test_next_url_returns_false_once_theres_no_more_urls(  ) {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png">';
		$p = new WP_Block_Markup_Url_Processor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertFalse( $p->next_url(), 'Found more URLs than expected.' );
	}

	public function test_next_url_finds_urls_in_multiple_attributes(  ) {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png">';
		$p = new WP_Block_Markup_Url_Processor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://first-url.org', $p->get_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://mysite.com/wp-content/image.png', $p->get_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

	public function test_next_url_finds_urls_in_multiple_tags(  ) {
		$markup = '<img longdesc="https://first-url.org" src="https://mysite.com/wp-content/image.png"><a href="https://third-url.org">';
		$p = new WP_Block_Markup_Url_Processor( $markup );
		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://first-url.org', $p->get_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://mysite.com/wp-content/image.png', $p->get_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );

		$this->assertTrue( $p->next_url(), 'Failed to find the URL in the markup.' );
		$this->assertEquals( 'https://third-url.org', $p->get_url(), 'Found a URL in the markup, but it wasn\'t the expected one.' );
	}

}
