<?php
/**
 * Show local edit count instead of global (global is stored in
 * the user table, user.user_editcount) in Special:Preferences
 *
 * @file
 * @date 6 June 2010 (amended on 4 January 2015 to add caching)
 * @author Jack Phoenix <jack@shoutwiki.com>
 */

class EditcountAdditions {

	/**
	 * Fix the editcount on [[Special:Preferences]]
	 * by overwriting the core editcount
	 *
	 * @param User $user
	 * @param array &$defaultPreferences
	 */
	public static function onGetPreferences( $user, &$defaultPreferences ) {
		global $wgLang;
		// Overwrite core edit count
		$defaultPreferences['editcount']['default'] = $wgLang->formatNum( self::getRealEditcount( $user ) );
	}

	/**
	 * Get the real edit count of the given user, fetching it either from the
	 * cache or directly from the DB (and storing it in cache afterwards) if
	 * it's not cached.
	 *
	 * @param User $user User object whose edit count we want
	 * @return int Edit count (d'oh!)
	 */
	public static function getRealEditcount( $user ) {
		global $wgMemc;

		$uid = $user->getId();
		$key = $wgMemc->makeKey( 'editcount', 'accurate', $uid );
		$editCount = $wgMemc->get( $key );

		if ( $editCount === false ) {
			$dbr = wfGetDB( DB_REPLICA );

			// Query timing to determine for how long we should cache the data (HT ValhallaSW)
			$beginTime = microtime( true );
			$editCount = $dbr->selectField(
				'revision', 'COUNT(*)',
				[ 'rev_user' => $uid ],
				__METHOD__
			);
			$endTime = microtime( true ) - $beginTime;

			// $endTime is in seconds, so multiply it by 60 to get minutes
			$wgMemc->set( $key, $editCount, 60 * ( $endTime * 60 ) );
		}

		return $editCount;
	}

	// Bump the memcache key by one after a page has successfully been saved, as per legoktm
	public static function onPageContentSaveComplete(
		WikiPage $wikiPage, $user, $content, $summary, $isMinor, $isWatch,
		$section, $flags, $revision, $status, $baseRevId ) {
		global $wgMemc;
		// No need to run this code for anons since anons don't have preferences
		// nor does Special:Editcount work for them
		if ( $user && $user->isLoggedIn() ) {
			$key = $wgMemc->makeKey( 'editcount', 'accurate', $user->getId() );
			$wgMemc->incr( $key );
		}
	}

}