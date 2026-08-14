<?php

namespace MediaWiki\Extension\SaintapediaFeedback;

/**
 * Shared allowlists and filter normalization for the editor dashboard.
 * Kept free of MediaWiki services so unit tests can exercise it directly.
 */
class FeedbackFilters {

	public const VALID_STATUSES = [ 'new', 'reviewed', 'actioned', 'dismissed' ];

	public const VALID_CATEGORIES = [
		'inaccurate', 'outdated', 'needs-detail',
		'confusing', 'missing-sources', 'broken-links', 'other',
	];

	public const VALID_SORTS = [ 'newest', 'oldest' ];

	public const MAX_SEARCH_LENGTH = 100;

	public static function normalizeStatus( ?string $status, string $default = 'new' ): string {
		$status = $status ?? $default;
		if ( $status === 'all' ) {
			return 'all';
		}
		if ( !in_array( $status, self::VALID_STATUSES, true ) ) {
			return $default;
		}
		return $status;
	}

	public static function normalizeCategory( ?string $category ): string {
		$category = $category ?? 'all';
		if ( $category === 'all' ) {
			return 'all';
		}
		if ( !in_array( $category, self::VALID_CATEGORIES, true ) ) {
			return 'all';
		}
		return $category;
	}

	public static function normalizeSort( ?string $sort ): string {
		$sort = $sort ?? 'newest';
		if ( !in_array( $sort, self::VALID_SORTS, true ) ) {
			return 'newest';
		}
		return $sort;
	}

	/**
	 * Normalize free-text search for LIKE queries.
	 * Returns empty string if nothing usable remains.
	 *
	 * Does not strip %, _, or \ — Database::buildLike() / LikeValue already escapes
	 * those in literal fragments, so removing them only mangled legitimate searches
	 * (e.g. "50%", "under_score").
	 */
	public static function sanitizeSearch( ?string $q ): string {
		if ( $q === null ) {
			return '';
		}
		$q = trim( $q );
		if ( $q === '' ) {
			return '';
		}
		if ( mb_strlen( $q ) > self::MAX_SEARCH_LENGTH ) {
			$q = mb_substr( $q, 0, self::MAX_SEARCH_LENGTH );
		}
		return $q;
	}

	/** @return string[] Allowlisted status actions for process/bulk */
	public static function processActions(): array {
		return [ 'reviewed', 'actioned', 'dismissed' ];
	}

	/**
	 * Build FeedbackStore::updateStatus $opts from a single-item status POST.
	 *
	 * workNote is included only when the field was submitted and non-empty, so
	 * reviewed/dismissed forms (no textarea) do not NULL an existing note.
	 *
	 * @param string|null $workNote Raw POST value, or null if the field was absent
	 * @param string|null $resolutionSummary
	 * @return array{resolutionPublic:bool,resolutionSummary:string,workNote?:string}
	 */
	public static function statusUpdateOpts(
		?string $workNote,
		?string $resolutionSummary,
		bool $makePublic
	): array {
		$opts = [
			'resolutionPublic' => $makePublic,
			'resolutionSummary' => $resolutionSummary ?? '',
		];
		if ( $workNote !== null && trim( $workNote ) !== '' ) {
			$opts['workNote'] = $workNote;
		}
		return $opts;
	}
}
