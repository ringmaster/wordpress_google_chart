<?php
/*
Plugin Name: Google Charts
Plugin URI: http://wordpress.org/extend/plugins/google-charts/
Description: Embed CSV files as Google Charts within your posts
Author: Owen Winkler
Version: 1.0
Author URI: http://asymptomatic.net
*/

class GoogleChartsPlugin {

	const MY_PLUGIN_VERSION = '1.0';

	public function __construct() {
		add_action('wp_enqueue_scripts', array($this, 'action_wp_enqueue_scripts'));
		add_shortcode( 'chart', array($this, 'shortcode_chart') );
		add_filter('media_send_to_editor', array($this, 'filter_media_send_to_editor'), 10, 3);
		add_filter('attachment_fields_to_edit', array($this, 'filter_attachment_fields_to_edit'), 10, 2);
		add_filter('attachment_fields_to_save', array($this, 'filter_attachment_fields_to_save'), 10, 2);
	}

	public function filter_attachment_fields_to_edit($form_fields, $post) {
		if ( $post->post_mime_type == 'text/csv' ) {
			$graph_options = get_post_meta($post->ID, '_wp_attachment_graph_options', true);
			if ( empty($graph_options) ) {
				$graph_options = '';
			}

			$form_fields['graph_options'] = array(
				'value' => $graph_options,
				'label' => __('Graph Options'),
				'input' => 'textarea',
				'helps' => __('Supply JSON-formatted graph options for output of this graph'),
			);

			$form_fields['url']['html'] .= '<button type="button" class="button chartcode" title="[chart data=' . $post->ID . ' ref=' . $post->post_title . ']"
			onclick="jQuery(this).parents(\'.media-item\').find(\'.urlfield\').val(jQuery(this).attr(\'title\'))">CSV as Chart</button>';
		}
		return $form_fields;
	}

	public function filter_attachment_fields_to_save($post, $attachment) {
		if ( isset($attachment['graph_options']) ) {
			$graph_options = get_post_meta($post['ID'], '_wp_attachment_graph_options', true);
			if ( $graph_options != stripslashes($attachment['graph_options']) ) {
				$graph_options = wp_strip_all_tags( stripslashes($attachment['graph_options']), true );
				update_post_meta( $post['ID'], '_wp_attachment_graph_options', addslashes($graph_options) );
			}
		}
		return $post;
	}

	public function filter_media_send_to_editor($html, $send_id = null, $attachment = null) {
		if(preg_match('#\[chart [^\]]+\]#i', $html, $matches)) {
			$html = $matches[0];
		}
		return $html;
	}

	public function action_wp_enqueue_scripts() {
		wp_enqueue_script('jquery');
		wp_enqueue_script('google-jsapi', 'https://www.google.com/jsapi');
	}

	public function shortcode_chart($attributes) {
		$attributes = array_merge(
			array(
				'type' => 'ColumnChart',
			),
			$attributes
		);

		$csvfile = wp_get_attachment_url($attributes['data']);
		$handle = fopen($csvfile, "r");
		$data = array();
		while ($rowdata = fgetcsv($handle)) {
			array_push($data, $rowdata);
		}
		fclose($handle);
		$types = array_map('strtolower', array_shift($data));
		$names = array_shift($data);

		if(isset($attributes['id'])) {
			$chart_dom_id = $attributes['id'];
		}
		else {
			$chart_dom_id = 'chart_' . sprintf('%x', crc32(json_encode($data)));
		}
		$output = <<< CHART_JS_INIT
<div id="{$chart_dom_id}"></div>
<script type="text/javascript">
	function loadScript(c,a){var b=document.createElement("script");b.type="text/javascript";b.readyState?b.onreadystatechange=function(){if("loaded"==b.readyState||"complete"==b.readyState)b.onreadystatechange=null,a()}:b.onload=function(){a()};b.src=c;document.getElementsByTagName("head")[0].appendChild(b)}if("undefined"===typeof googleCallbacks)var googleCallbacks=[];function googleChartLoaded(){googleCoreChartLoaded=!0;for(var c=googleCallbacks.length,a=0;a<c;a++)googleCallbacks[a]()}
	function googleLoadCallback(c){if("undefined"===typeof a){var a=!1;loadScript("https://www.google.com/jsapi",function(){google.load("visualization","1.0",{packages:["corechart"],callback:googleChartLoaded})})}a?c():googleCallbacks.push(c)};
	googleLoadCallback(function(){
	var data = new google.visualization.DataTable();
CHART_JS_INIT;
		foreach($names as $name) {
			$output .= 'data.addColumn("' . current($types) . '", "' . $name . '");';
			next($types);
		}
		$output .= 'data.addRows([';
		$rowcomma = '';
		reset($types);
		foreach($data as $row) {
			$output .= $rowcomma . '[';
			reset($row);
			$comma = '';
			foreach($types as $type) {
				$output .= $comma;
				switch($type) {
					case 'string':
						$output .= '"' . addslashes(current($row)) . '"';
						break;
					case 'number':
						$output .= current($row);
						break;
					default:
						$output .= '"Invalid type in CSV"';
						break;
				}
				next($row);
				$comma = ',';
			}
			$output .= ']';
			$rowcomma = ',';
		}
		$output .= ']);';

		$options = get_post_meta($attributes['data'], '_wp_attachment_graph_options', true);
		if($options == '') {
			$options = '{}';
		}

		$output .= <<< CHART_JS
var chart = new google.visualization.{$attributes['type']}(document.getElementById('{$chart_dom_id}'));
chart.draw(data, {$options});
});
CHART_JS;
		$output .= '</script>';

		return $output;
	}
}

new GoogleChartsPlugin();

?>