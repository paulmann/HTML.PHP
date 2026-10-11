<?php

declare(strict_types=1);

namespace HtmlPhp\WordPress\Maintenance;

final class Cron
{
    private function __construct()
    {
    }

    /**
     * Schedules a recurring event when it is not already scheduled.
     *
     * @param list<mixed> $args
     */
    public static function schedule(string $hook, string $recurrence = 'daily', array $args = []): bool
    {
        if (!function_exists('wp_next_scheduled')) {
            throw new TemplateException('WP-Cron is not available.');
        }

        if (wp_next_scheduled($hook, $args) !== false) {
            return false;
        }

        return (bool) wp_schedule_event(time(), $recurrence, $hook, $args);
    }

    /**
     * @param list<mixed> $args
     */
    public static function unschedule(string $hook, array $args = []): bool
    {
        if (!function_exists('wp_next_scheduled')) {
            throw new TemplateException('WP-Cron is not available.');
        }

        $timestamp = wp_next_scheduled($hook, $args);

        if ($timestamp === false) {
            return false;
        }

        return (bool) wp_unschedule_event((int) $timestamp, $hook, $args);
    }

    /**
     * @param list<mixed> $args
     */
    public static function next(string $hook, array $args = []): ?int
    {
        if (!function_exists('wp_next_scheduled')) {
            return null;
        }

        $timestamp = wp_next_scheduled($hook, $args);

        return $timestamp === false ? null : (int) $timestamp;
    }

    /**
     * True when native WP-Cron appears to be disabled (external cron in use).
     */
    public static function disabledByConstant(): bool
    {
        return \defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON === true;
    }
}
