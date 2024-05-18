<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\Database;

/**
 * Show local edit count instead of global (global is stored in
 * the user table, user.user_editcount) in Special:Preferences
 *
 * @file
 * @date 26 July 2019
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
		$defaultPreferences['editcount']['default'] =
			$wgLang->formatNum( self::getRealEditcount( $user ) );
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
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$key = $cache->makeKey( 'editcount', 'accurate', $user->getId() );
		$fname = __METHOD__;

		return $cache->getWithSetCallback(
			$key,
			$cache::TTL_HOUR,
			static function ( $oldValue, &$ttl, array &$setOpts ) use ( $user, $fname ) {
				$dbr = wfGetDB( DB_REPLICA );
				$setOpts += Database::getCacheSetOptions( $dbr );

				// Query timing to determine for how long we should cache the data (HT ValhallaSW)
				$beginTime = microtime( true );

				$editCount = $dbr->selectField(
					'revision',
					'COUNT(*)',
					[ 'rev_actor' => $user->getActorId() ],
					$fname
				);

				$endTime = microtime( true );

				$ttl = min( $ttl, 60 * (int)max( $endTime - $beginTime, 1 ) );

				return $editCount;
			},
			[ 'checkKeys' => [ $key ], 'lockTSE' => 30 ]
		);
	}

	/**
	 * Bump the memcache key by one after a page has successfully been saved, as per legoktm
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage, UserIdentity $user ) {
		// No need to run this code for anons since anons don't have preferences
		// nor does Special:Editcount work for them
		if ( $user->isRegistered() ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cache->touchCheckKey( $cache->makeKey( 'editcount', 'accurate', $user->getId() ) );
		}
	}

}
