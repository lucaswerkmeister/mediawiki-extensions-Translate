<?php
/**
 * Contains logic for special page Special:Translations.
 *
 * @file
 * @author Siebrand Mazeland
 * @author Niklas Laxstörm
 * @license GPL-2.0-or-later
 */

/**
 * Implements a special page which shows all translations for a message.
 * Bits taken from SpecialPrefixindex.php and TranslateTasks.php
 *
 * @ingroup SpecialPage TranslateSpecialPage
 */
class SpecialTranslations extends SpecialAllPages {
	use CompatibleLinkRenderer;

	public function __construct() {
		parent::__construct( 'Translations' );
	}

	protected function getGroupName() {
		return 'pages';
	}

	function getDescription() {
		return $this->msg( 'translations' )->text();
	}

	/**
	 * Entry point : initialise variables and call subfunctions.
	 * @param string $par Message key. Becomes "MediaWiki:Allmessages" when called like
	 *             Special:Translations/MediaWiki:Allmessages (default null)
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.translate.legacy' );

		$par = (string)$par;

		if ( $this->including() ) {
			$title = Title::newFromText( $par );
			if ( !$title ) {
				$out->addWikiMsg( 'translate-translations-including-no-param' );
			} else {
				$this->showTranslations( $title );
			}

			return;
		}

		/**
		 * GET values.
		 */
		$request = $this->getRequest();
		$message = $request->getText( 'message' );
		$namespace = $request->getInt( 'namespace', NS_MAIN );

		if ( $message !== '' ) {
			$title = Title::newFromText( $message, $namespace );
		} else {
			$title = Title::newFromText( $par, $namespace );
		}

		$out->addHelpLink(
			'Help:Extension:Translate/Statistics_and_reporting#Translations_in_all_languages'
		);

		if ( !$title ) {
			$title = Title::makeTitle( NS_MEDIAWIKI, '' );
			$out->addHTML( $this->namespaceMessageForm( $title ) );
		} else {
			$out->addHTML( $this->namespaceMessageForm( $title ) . '<br />' );
			$this->showTranslations( $title );
		}
	}

	/**
	 * Message input fieldset
	 *
	 * @param Title|null $title (default: null)
	 * @return string HTML for fieldset.
	 */
	protected function namespaceMessageForm( Title $title = null ) {
		global $wgScript;

		$namespaces = new XmlSelect( 'namespace', 'namespace' );
		$namespaces->setDefault( $title->getNamespace() );

		foreach ( $this->getSortedNamespaces() as $text => $index ) {
			$namespaces->addOption( $text, $index );
		}

		$out = Xml::openElement( 'div', [ 'class' => 'namespaceoptions' ] );
		$out .= Xml::openElement( 'form', [ 'method' => 'get', 'action' => $wgScript ] );
		$out .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() );
		$out .= Xml::openElement( 'fieldset' );
		$out .= Xml::element(
			'legend',
			null,
			$this->msg( 'translate-translations-fieldset-title' )->text()
		);
		$out .= Xml::openElement( 'table', [ 'id' => 'nsselect', 'class' => 'allpages' ] );
		$out .= "<tr>
				<td class='mw-label'>" .
			Xml::label( $this->msg( 'translate-translations-messagename' )->text(), 'message' ) .
			"</td>
				<td class='mw-input'>" .
			Xml::input( 'message', 30, $title->getText(), [ 'id' => 'message' ] ) .
			"</td>
			</tr>
			<tr>
				<td class='mw-label'>" .
			Xml::label( $this->msg( 'translate-translations-project' )->text(), 'namespace' ) .
			"</td>
				<td class='mw-input'>" .
			$namespaces->getHTML() . ' ' .
			Xml::submitButton( $this->msg( 'allpagessubmit' )->text() ) .
			'</td>
				</tr>';
		$out .= Xml::closeElement( 'table' );
		$out .= Xml::closeElement( 'fieldset' );
		$out .= Xml::closeElement( 'form' );
		$out .= Xml::closeElement( 'div' );

		return $out;
	}

	/**
	 * Returns sorted array of namespaces.
	 *
	 * @return array ( string => int )
	 */
	public function getSortedNamespaces() {
		global $wgTranslateMessageNamespaces, $wgContLang;

		$nslist = [];
		foreach ( $wgTranslateMessageNamespaces as $ns ) {
			$nslist[$wgContLang->getFormattedNsText( $ns )] = $ns;
		}
		ksort( $nslist );

		return $nslist;
	}

	/**
	 * Builds a table with all translations of $title.
	 *
	 * @param Title $title (default: null)
	 */
	protected function showTranslations( Title $title ) {
		$handle = new MessageHandle( $title );
		$namespace = $title->getNamespace();
		$message = $handle->getKey();

		if ( !$handle->isValid() ) {
			$this->getOutput()->addWikiMsg( 'translate-translations-no-message', $title->getPrefixedText() );

			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$res = $dbr->select( 'page',
			[ 'page_namespace', 'page_title' ],
			[
				'page_namespace' => $namespace,
				'page_title ' . $dbr->buildLike( "$message/", $dbr->anyString() ),
			],
			__METHOD__,
			[
				'ORDER BY' => 'page_title',
				'USE INDEX' => 'name_title',
			]
		);

		if ( !$res->numRows() ) {
			$this->getOutput()->addWikiMsg(
				'translate-translations-no-message',
				$title->getPrefixedText()
			);

			return;
		} else {
			$this->getOutput()->addWikiMsg(
				'translate-translations-count',
				$this->getLanguage()->formatNum( $res->numRows() )
			);
		}

		// Normal output.
		$titles = [];

		foreach ( $res as $s ) {
			$titles[] = $s->page_title;
		}

		$pageInfo = TranslateUtils::getContents( $titles, $namespace );

		$tableheader = Xml::openElement( 'table', [
			'class' => 'mw-sp-translate-table sortable'
		] );

		$tableheader .= Xml::openElement( 'tr' );
		$tableheader .= Xml::element( 'th', null, $this->msg( 'allmessagesname' )->text() );
		$tableheader .= Xml::element( 'th', null, $this->msg( 'allmessagescurrent' )->text() );
		$tableheader .= Xml::closeElement( 'tr' );

		// Adapted version of TranslateUtils:makeListing() by Nikerabbit.
		$out = $tableheader;

		$canTranslate = $this->getUser()->isAllowed( 'translate' );

		$historyText = '&#160;<sup>' .
			$this->msg( 'translate-translations-history-short' )->escaped() .
			'</sup>&#160;';
		$separator = $this->msg( 'word-separator' )->plain();

		foreach ( $res as $s ) {
			$key = $s->page_title;
			$tTitle = Title::makeTitle( $s->page_namespace, $key );
			$tHandle = new MessageHandle( $tTitle );

			$code = $tHandle->getCode();

			$text = TranslateUtils::getLanguageName( $code, $this->getLanguage()->getCode() );
			$text .= $separator;
			$text .= $this->msg( 'parentheses' )->params( $code )->plain();
			$tools['edit'] = Html::element(
				'a',
				[ 'href' => TranslateUtils::getEditorUrl( $tHandle ) ],
				$text
			);

			$tools['history'] = $this->makeLink(
				$tTitle,
				$historyText,
				[
					'action',
					'title' => $this->msg( 'history-title', $tTitle->getPrefixedDBkey() )->text()
				],
				[ 'action' => 'history' ]
			);

			if ( MessageHandle::hasFuzzyString( $pageInfo[$key][0] ) || $tHandle->isFuzzy() ) {
				$class = 'orig';
			} else {
				$class = 'def';
			}

			$languageAttributes = [];
			if ( Language::isKnownLanguageTag( $code ) ) {
				$language = Language::factory( $code );
				$languageAttributes = [
					'lang' => $language->getHtmlCode(),
					'dir' => $language->getDir(),
				];
			}

			$formattedContent = TranslateUtils::convertWhiteSpaceToHTML( $pageInfo[$key][0] );

			$leftColumn = $tools['history'] . $tools['edit'];
			$out .= Xml::tags( 'tr', [ 'class' => $class ],
				Xml::tags( 'td', null, $leftColumn ) .
					Xml::tags( 'td', $languageAttributes, $formattedContent )
			);
		}

		$out .= Xml::closeElement( 'table' );
		$this->getOutput()->addHTML( $out );
	}
}
