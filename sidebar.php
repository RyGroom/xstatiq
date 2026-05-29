<?php
/**
 * Sidebar template
 */
if ( ! is_active_sidebar( 'sidebar-1' ) ) {
    return;
}
?>
<aside class="widget-area" aria-label="Sidebar">
    <?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
