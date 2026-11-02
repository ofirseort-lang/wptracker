<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds the text of llms.txt ('short') and llms-full.txt ('full').
 *
 * Reads only stored data: it never makes HTTP requests, so a rebuild is fast
 * (NFR-1). Meta tags are fetched by the nightly job (RT_LLMS_Fetcher).
 *
 * v0.1: entries are grouped by post type label, sorted by title. Topic
 * domains, featured articles and facts (FR-12..FR-16, FR-33) are not built yet.
 */
class RT_LLMS_Builder {

	/** @var array */
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function build( $which ) {
		$full = ( 'full' === $which );
		$out  = array();

		$out[] = '# ' . $this->clean_line( $this->settings['title'] ) . ( $full ? ' (full)' : '' );
		$out[] = '';
		if ( '' !== trim( (string) $this->settings['summary'] ) ) {
			$out[] = '> ' . $this->clean_line( $this->settings['summary'] );
			$out[] = '';
		}
		$facts = $this->facts_block();
		if ( $facts ) {
			$out[] = $facts;
		}
		$out[] = $full
			? '- Focused version: [llms.txt](' . home_url( '/llms.txt' ) . ')'
			: '- Complete version: [llms-full.txt](' . home_url( '/llms-full.txt' ) . ')';
		$out[] = '';

		foreach ( $this->groups() as $label => $posts ) {
			$out[] = '## ' . $this->clean_line( $label );
			foreach ( $posts as $post ) {
				$out[] = $this->entry( $post, $full );
			}
			$out[] = '';
		}

		return rtrim( implode( "\n", $out ) ) . "\n";
	}

	private function facts_block() {
		$lines = array();
		foreach ( (array) $this->settings['facts'] as $fact ) {
			if ( ! empty( $fact['label'] ) && ! empty( $fact['value'] ) ) {
				$lines[] = '- ' . $this->clean_line( $fact['label'] ) . ': ' . $this->clean_line( $fact['value'] );
			}
		}
		return $lines ? implode( "\n", $lines ) : '';
	}

	/** @return array<string,WP_Post[]> post type label => posts */
	private function groups() {
		$groups = array();
		foreach ( (array) $this->settings['post_types'] as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'              => $type,
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => -1,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);
			$posts = array_values( array_filter( $posts, array( $this, 'included' ) ) );
			if ( $posts ) {
				$groups[ $obj->labels->name ] = $posts;
			}
		}
		return $groups;
	}

	/** FR-8 */
	private function included( $post ) {
		if ( get_post_meta( $post->ID, '_rt_llms_exclude', true ) ) {
			return false;
		}
		$meta = get_post_meta( $post->ID, RT_LLMS_Fetcher::META, true );
		if ( is_array( $meta ) && ! empty( $meta['ok'] ) ) {
			if ( false !== stripos( $meta['robots'], 'noindex' ) ) {
				return false;
			}
			if ( '' !== $meta['canonical'] && $this->norm( $meta['canonical'] ) !== $this->norm( get_permalink( $post ) ) ) {
				return false;
			}
		}
		return true;
	}

	private function entry( $post, $full ) {
		$title = $this->clean_line( get_the_title( $post ) );
		$title = str_replace( array( '[', ']' ), array( '(', ')' ), $title );
		$line  = '- [' . $title . '](' . $this->url( get_permalink( $post ) ) . ')';
		$desc  = $this->description( $post, $full ? (int) $this->settings['len_full'] : (int) $this->settings['len_short'] );
		return '' === $desc ? $line : $line . ': ' . $desc;
	}

	/** FR-10: rendered meta description, then excerpt, then content. */
	private function description( $post, $max ) {
		$meta = get_post_meta( $post->ID, RT_LLMS_Fetcher::META, true );
		$text = ( is_array( $meta ) && ! empty( $meta['ok'] ) ) ? $meta['desc'] : '';
		if ( '' === $text ) {
			$text = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			$text = strip_shortcodes( $text );
		}
		$text = $this->clean_line( wp_strip_all_tags( $text ) );
		if ( mb_strlen( $text ) > $max ) {
			$cut  = mb_substr( $text, 0, $max );
			$pos  = mb_strrpos( $cut, ' ' );
			$text = rtrim( false !== $pos ? mb_substr( $cut, 0, $pos ) : $cut, ",;:- \t" ) . '…';
		}
		return $text;
	}

	/** Collapse whitespace so a value can never break the Markdown structure (FR-11). */
	private function clean_line( $s ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** FR-9: percent-encode each path segment exactly once. */
	private function url( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return $url;
		}
		$path = isset( $p['path'] ) ? implode( '/', array_map( 'rawurlencode', array_map( 'rawurldecode', explode( '/', $p['path'] ) ) ) ) : '';
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	private function norm( $url ) {
		return strtolower( untrailingslashit( rawurldecode( $url ) ) );
	}
}
