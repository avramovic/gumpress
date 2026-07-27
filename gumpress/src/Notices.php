<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Tiny queue for bootstrap-level admin notices (v1/v2 conflicts, malformed
 * encrypted config, engine init failures, the non-production unsealed-config
 * warning, a server-supplied update-check notice) that aren't tied to a
 * specific, already-constructed Module. Never fatals, never throws.
 */
final class Notices
{
    /** @var array<int, array{content: string, level: string, escape: bool}> */
    private static array $messages = [];

    private static bool $hooked = false;

    public static function queue(string $message, string $level = 'error'): void
    {
        self::enqueue($message, $level, true);
    }

    /**
     * Like queue(), but $html is trusted, already-safe markup (built with
     * esc_html()/esc_url() by the caller) and is not escaped again at render
     * time — needed for notices that embed a link, such as the update
     * checker's "your license isn't valid for this domain" message.
     */
    public static function queue_html(string $html, string $level = 'error'): void
    {
        self::enqueue($html, $level, false);
    }

    private static function enqueue(string $content, string $level, bool $escape): void
    {
        self::$messages[] = ['content' => $content, 'level' => $level, 'escape' => $escape];

        if (!self::$hooked && function_exists('add_action')) {
            self::$hooked = true;
            add_action('admin_notices', [self::class, 'render']);
        }
    }

    public static function render(): void
    {
        $seen = [];
        foreach (self::$messages as $notice) {
            $dedupe_key = $notice['level'] . '|' . $notice['content'];
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $seen[$dedupe_key] = true;

            $class = 'notice notice-' . preg_replace('/[^a-z]/', '', $notice['level']);
            $body = $notice['escape'] ? esc_html($notice['content']) : $notice['content'];

            printf('<div class="%s"><p>%s</p></div>', esc_attr($class), $body);
        }
    }

    /** @internal test-only. */
    public static function reset_for_tests(): void
    {
        self::$messages = [];
        self::$hooked = false;
    }

    /**
     * @internal test-only.
     * @return array<int, array{content: string, level: string, escape: bool}>
     */
    public static function queued_for_tests(): array
    {
        return self::$messages;
    }
}
