<?php

use Vcms\HtmlView;


class TestView extends HtmlView
{
	public function __construct($params){
	}
	public function render(){
	}
	public function getBody(){
		return "12345";
	}
	public function isCacheable(){
	}

}
?>
