<?php
/**
 * Template for Bsync Member Category archives.
 *
 * URL example: /member-category/{slug}/
 *
 * Shows a grid of member pages in the current category. Access is still
 * protected by the main plugin (only logged-in users with portal access
 * can see it).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

$term = get_queried_object();
?>

<div class="bsync-member-category-archive">
    <div class="wrap">
        <header class="bsync-member-category-header">
            <h1 class="bsync-member-category-title"><?php echo esc_html( ( $term && ! is_wp_error( $term ) ) ? $term->name : '' ); ?></h1>

            <?php if ( term_description() ) : ?>
                <div class="bsync-member-category-description">
                    <?php echo wp_kses_post( term_description() ); ?>
                </div>
            <?php endif; ?>
        </header>

        <?php if ( have_posts() ) : ?>
            <div class="bsync-member-grid">
                <?php
                while ( have_posts() ) :
                    the_post();
                    ?>
                    <article id="post-<?php the_ID(); ?>" <?php post_class( 'bsync-member-card' ); ?>>
                        <h2 class="bsync-member-card-title">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h2>

                        <div class="bsync-member-card-excerpt">
                            <?php
                            if ( has_excerpt() ) {
                                the_excerpt();
                            } else {
                                echo wp_kses_post( wp_trim_words( get_the_content( null, false ), 30, '…' ) );
                            }
                            ?>
                        </div>
                    </article>
                <?php endwhile; ?>
            </div>

            <div class="bsync-member-pagination">
                <?php the_posts_pagination(); ?>
            </div>
        <?php else : ?>
            <p><?php esc_html_e( 'No member pages found in this category.', 'bsync-member' ); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php
get_footer();
