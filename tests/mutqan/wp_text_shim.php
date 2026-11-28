<?php
// WordPress text helpers the SEO engine uses (2026-11-23), as far as the offline tests need them.
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s, $br = false) { $s = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s); $s = strip_tags($s); return trim($br ? preg_replace('/[\r\n\t ]+/', ' ', $s) : $s); } }
if (!function_exists('sanitize_title')) { function sanitize_title($s) { $s = mb_strtolower(trim(strip_tags((string) $s))); $s = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $s); return trim(preg_replace('/[\s-]+/u', '-', $s), '-'); } }
