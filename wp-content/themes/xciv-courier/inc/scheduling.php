<?php
/**
 * Action Scheduler / WP-Cron helpers for membership jobs.
 *
 * Prefer WooCommerce Action Scheduler when present; fall back to wp_cron.
 *
 * @package XCIV_Courier
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule a one-off action.
 *
 * @param string               $hook      Hook name.
 * @param int                  $timestamp Unix timestamp.
 * @param array<int|string,mixed> $args   Hook args.
 * @param string               $group     Action Scheduler group.
 */
function xciv_schedule_once( string $hook, int $timestamp, array $args = array(), string $group = 'xciv' ): bool {
	$hook = sanitize_key( $hook );
	if ( $hook === '' ) {
		return false;
	}
	if ( $timestamp <= time() ) {
		$timestamp = time() + 60;
	}

	if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
		if ( ! as_has_scheduled_action( $hook, $args, $group ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, $group );
		}
		return true;
	}

	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_single_event( $timestamp, $hook, $args );
	}

	return true;
}

/**
 * Schedule a recurring action.
 *
 * @param string               $hook             Hook name.
 * @param int                  $interval_seconds Interval.
 * @param array<int|string,mixed> $args          Hook args.
 * @param string               $group            AS group.
 * @param int                  $first_run        First run timestamp.
 */
function xciv_schedule_recurring( string $hook, int $interval_seconds, array $args = array(), string $group = 'xciv', int $first_run = 0 ): bool {
	$hook = sanitize_key( $hook );
	if ( $hook === '' ) {
		return false;
	}

	$interval_seconds = max( MINUTE_IN_SECONDS, $interval_seconds );
	$first_run        = $first_run > 0 ? $first_run : ( time() + HOUR_IN_SECONDS );

	if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
		if ( ! as_has_scheduled_action( $hook, $args, $group ) ) {
			as_schedule_recurring_action( $first_run, $interval_seconds, $hook, $args, $group );
		}
	}

	$wp_recurrence = $interval_seconds >= DAY_IN_SECONDS ? 'daily' : 'hourly';
	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_event( $first_run, $wp_recurrence, $hook, $args );
	}

	return true;
}

/**
 * Clear scheduled actions for a hook + args.
 *
 * @param string               $hook  Hook.
 * @param array<int|string,mixed> $args Args.
 * @param string               $group AS group.
 */
function xciv_unschedule_all( string $hook, array $args = array(), string $group = 'xciv' ): void {
	$hook = sanitize_key( $hook );
	if ( $hook === '' ) {
		return;
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $hook, $args, $group );
	}
	wp_clear_scheduled_hook( $hook, $args );
}
