<?php

use PHPUnit\Framework\TestCase;

class WP_URL_In_Text_Processor_Tests extends TestCase
{

    /**
     *
     * @dataProvider provider_test_finds_next_url
     */
    public function test_next_url_finds_the_url($url, $text, $index=0)
	{
		$p = new WP_URL_In_Text_Processor($text);
		for($i = 0; $i <= $index; $i++) {
			$this->assertTrue($p->next_url(), 'Failed to find the URL in the text.');
		}
		$this->assertEquals($url, $p->get_url(), 'Found a URL in the text, but it wasn\'t the expected one.');
    }

    static public function provider_test_finds_next_url()
    {
        return [
			'Absolute URL' => ['https://wordpress.org', 'Have you seen https://wordpress.org?'],
			'Second absolute URL' => ['https://w.org', 'Have you seen https://wordpress.org or https://w.org?', 1],
			'Domain-only' => ['www.example.com', 'Visit www.example.com'],
			'Domain + path' => ['www.example.com/path', 'Visit www.example.com/path'],
			'UTF-8 domain' => ['łąka.pl', 'Więcej na łąka.pl'],
			'ASCII path' => ['https://w.org/plugins?', 'Visit the WordPress plugins directory https://w.org/plugins?łąka=1'],
			'Encoded path' => ['https://w.org/plugins?%C5%82%C4%85ka=1', 'Visit the WordPress plugins directory https://w.org/plugins?%C5%82%C4%85ka=1'],
			'Closing parenthesis after the path' => ['https://w.org/plugins', 'Visit the WordPress plugins directory (https://w.org/plugins)'],
			'Parenthesis within the path' => ['https://w.org/plug(in)s', 'Visit the WordPress plugins directory (https://w.org/plug(in)s'],
        ];
    }
}
