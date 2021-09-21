<?php
/**
 * Block class.
 *
 * @package SiteCounts
 */

namespace XWP\SiteCounts;

use WP_Block;
use WP_Query;

/**
 * The Site Counts dynamic block.
 *
 * Registers and renders the dynamic block.
 */
class Block {

	/**
	 * The Plugin instance.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Instantiates the class.
	 *
	 * @param Plugin $plugin The plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds the action to register the block.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	/**
	 * Registers the block.
	 */
	public function register_block() {
		register_block_type_from_metadata(
			$this->plugin->dir(),
			[
				'render_callback' => [ $this, 'render_callback' ],
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @param array    $attributes The attributes for the block.
	 * @param string   $content    The block content, if any.
	 * @param WP_Block $block      The instance of this block.
	 * @return string The markup of the block.
	 */
	public function render_callback( $attributes, $content, $block ) {
		$class_name = $attributes['className'];
		$post_id    = get_the_ID();
		ob_start();
		?>
		<div class="<?php echo esc_attr( $class_name ); ?>">
			<h2>Post Counts</h2>
			<ul>
				<?php foreach ( $this->get_post_type_counter() as $post_label => $post_count ) : ?>
					<li>
						<?php echo esc_html( "There are $post_count $post_label." ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<?php echo esc_html( "The current post ID is $post_id." ); ?>
			</p>

			<?php
			/**
			 * For simplicity, and lacking context of where this would be used, i will limit the query to 30 results.
			 * Understanding that they will be enough to get at least 5 matches.
			 * In a real world scenario, this strategy should be revised and probably have a fallback in case not enough
			 * results are retrieved.
			 */
			?>
			<?php $baz_posts = $this->get_posts_by_category( 'baz', 30 ); ?>
			<?php
			/**
			 * To avoid a multi-dimensional query, we filter in PHP tag and post__not_in.
			 */
			?>
			<?php $foo_posts = $this->filter_tag_posts( $baz_posts, 'foo', $post_id ); ?>
			<?php if ( ! empty( $foo_posts ) ) : ?>
				<?php
				/**
				 * As in this h2, doesn't mention the need of meta_value == 'Accepted' I decided to ignore it.
				 * If it would be needed, and as it is not advise to look by meta_value for its non performance.
				 * Depending on the needs and specifications of the project we would need to fine the most convinient strategy.
				 * For instance, creating an index:
				 * ALTER TABLE `wp_postmeta` ADD INDEX `wp_postmeta_idx_meta_key_meta_value_post_id` (`meta_key` (255),`meta_value` (255), `post_id`);
				 */
				?>
				<h2>Any 5 posts with the tag of foo and the category of baz</h2>
				<ul>
					<?php foreach ( $foo_posts as $post_id ) : ?>
						<li><?php echo esc_html( get_the_title( $post_id ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Gets post and pages belonging to category $category_name.
	 *
	 * @param string $category_name Category name to query.
	 * @param int    $posts_per_page Posts per page.
	 *
	 * @return WP_Query
	 */
	protected function get_posts_by_category( $category_name, $posts_per_page ) {
		$query = new WP_Query(
			[
				'post_type'              => [ 'post', 'page' ],
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'date_query'             => [
					[
						'hour'    => 9,
						'compare' => '>=',
					],
					[
						'hour'    => 17,
						'compare' => '<=',
					],
				],
				'tax_query'              => [
					[
						'taxonomy'         => 'category',
						'field'            => 'name',
						'terms'            => $category_name,
						'include_children' => false,
					],
				],
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'posts_per_page'         => $posts_per_page,
			]
		);
		return $query;
	}

	/**
	 * Creates an array that will count number of posts per post type.
	 *
	 * @return array Associative array with 'label name' => number of posts.
	 */
	protected function get_post_type_counter() {
		$post_types = get_post_types( [ 'public' => true ] );
		$counter    = [];
		foreach ( $post_types as $post_type_slug ) {
			$status                                     = ( 'attachment' === $post_type_slug ) ? 'inherit' : 'publish';
			$post_count                                 = wp_count_posts( $post_type_slug )->$status;
			$post_type_object                           = get_post_type_object( $post_type_slug );
			$counter[ $post_type_object->labels->name ] = $post_count;
		}
		return $counter;
	}

	/**
	 * Given a Query result and a tag name, filter posts that has $tag_name associated.
	 * We also mock the WP_Query functionality $post__not_in.
	 *
	 * @param WP_Query $query WP_Query result where we will filter from.
	 * @param string   $tag_name Tag Name to filter.
	 * @param int      $post__not_in Specify post NOT to retrieve.
	 *
	 * @return array
	 */
	protected function filter_tag_posts( WP_Query $query, $tag_name, $post__not_in ) {
		$post_ids = [];
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				if ( ! $this->post_has_tag( $post_id, $tag_name ) ) {
					continue;
				}
				if ( $post_id === $post__not_in ) {
					continue;
				}
				$post_ids[] = $post_id;
			}
		}
		return $post_ids;
	}

	/**
	 * Returns if a given post_id has $tag_name tag associated.
	 *
	 * @param int    $post_id Post_id to look up.
	 * @param string $tag_name Tag name to be looked up.
	 *
	 * @return bool
	 */
	protected function post_has_tag( $post_id, $tag_name ) {
		$tags = get_the_tags( $post_id );
		foreach ( $tags as $tag ) {
			if ( $tag->name === $tag_name ) {
				return true;
			}
		}
		return false;
	}
}
