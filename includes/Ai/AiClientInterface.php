<?php
/**
 * Contract for an AI provider client. Kept deliberately narrow — one
 * "send this prompt, get raw text back" method — so AiExtractor doesn't
 * care whether it's talking to Anthropic, OpenAI, or (later) a local LLM.
 *
 * @package Uws\Ai
 */

namespace Uws\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AiClientInterface {

	/**
	 * @param string $system_prompt Instructions (schema, output format).
	 * @param string $user_content  The cleaned page text/content to extract from.
	 * @return string|\WP_Error Raw model response text (expected to contain JSON).
	 */
	public function complete( $system_prompt, $user_content );
}
