<?php
/**
 * Plugin Name: Bsync Member
 * Description: Member roles, private member pages, and taxonomy that integrate with the bsynce CRM plugin.
 * Version: 0.1.0
 * Author: bsync.me
 * Text Domain: bsync-member
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Basic plugin constants.
define( 'BSYNC_MEMBER_VERSION', '0.1.0' );
define( 'BSYNC_MEMBER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSYNC_MEMBER_URL', plugin_dir_url( __FILE__ ) );

// Role & capability slugs (all prefixed with bsync_ to avoid conflicts).
if ( ! defined( 'BSYNC_MEMBER_MANAGER_ROLE' ) ) {
    define( 'BSYNC_MEMBER_MANAGER_ROLE', 'bsync_member_manager' );
}

if ( ! defined( 'BSYNC_MEMBER_ROLE' ) ) {
    define( 'BSYNC_MEMBER_ROLE', 'bsync_member' );
}

if ( ! defined( 'BSYNC_MEMBER_MANAGE_CAP' ) ) {
    define( 'BSYNC_MEMBER_MANAGE_CAP', 'bsync_manage_members' );
}

// Capability for front-end member portal access.
if ( ! defined( 'BSYNC_MEMBER_PORTAL_CAP' ) ) {
    define( 'BSYNC_MEMBER_PORTAL_CAP', 'bsync_view_member_portal' );
}

// CPT & taxonomy slugs.
if ( ! defined( 'BSYNC_MEMBER_PAGE_CPT' ) ) {
    define( 'BSYNC_MEMBER_PAGE_CPT', 'bsync_member_page' );
}

if ( ! defined( 'BSYNC_MEMBER_CATEGORY_TAX' ) ) {
    define( 'BSYNC_MEMBER_CATEGORY_TAX', 'bsync_member_category' );
}

/**
 * Plugin activation: create roles, add capabilities, and wire CRM access.
 */
function bsync_member_activate() {
    // Create / update Member Manager role.
    $manager_display_name = get_option( 'bsync_member_manager_label', __( 'Member Manager', 'bsync-member' ) );

    $manager_role = get_role( BSYNC_MEMBER_MANAGER_ROLE );
    if ( ! $manager_role ) {
        $manager_role = add_role(
            BSYNC_MEMBER_MANAGER_ROLE,
            $manager_display_name,
            array(
                'read'                  => true,
                BSYNC_MEMBER_MANAGE_CAP => true,
                BSYNC_MEMBER_PORTAL_CAP => true,
                // Allow Member Managers to create and manage users and set roles.
                'list_users'            => true,
                'edit_users'            => true,
                'promote_users'         => true,
                'create_users'          => true,
            )
        );
    } else {
        // Ensure it has our capabilities.
        $manager_role->add_cap( 'read' );
        $manager_role->add_cap( BSYNC_MEMBER_MANAGE_CAP );
        $manager_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );
        $manager_role->add_cap( 'list_users' );
        $manager_role->add_cap( 'edit_users' );
        $manager_role->add_cap( 'promote_users' );
        $manager_role->add_cap( 'create_users' );
    }

    // Create / update Member role.
    $member_display_name = get_option( 'bsync_member_label', __( 'Member', 'bsync-member' ) );

    $member_role = get_role( BSYNC_MEMBER_ROLE );
    if ( ! $member_role ) {
        $member_role = add_role(
            BSYNC_MEMBER_ROLE,
            $member_display_name,
            array(
                'read'                  => true,
                BSYNC_MEMBER_PORTAL_CAP => true,
            )
        );
    } else {
        $member_role->add_cap( 'read' );
        $member_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );
    }

    // Ensure Member Manager has CPT and taxonomy capabilities once registered.
    bsync_member_add_cpt_caps_to_role( BSYNC_MEMBER_MANAGER_ROLE );

    // Wire Member Manager to existing bsynce CRM capability if that plugin is present.
    $crm_cap = 'manage_bsynce_crm';
    if ( $manager_role ) {
        $manager_role->add_cap( $crm_cap );
    }

    // Ensure site administrators can always manage members and pages.
    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $admin_role->add_cap( BSYNC_MEMBER_MANAGE_CAP );
        $admin_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );

        // Give administrators the same CPT capabilities as the Member Manager.
        $cpt_caps = array(
            'edit_bsync_member_page',
            'read_bsync_member_page',
            'delete_bsync_member_page',
            'edit_bsync_member_pages',
            'edit_others_bsync_member_pages',
            'publish_bsync_member_pages',
            'read_private_bsync_member_pages',
            'delete_bsync_member_pages',
            'delete_private_bsync_member_pages',
            'delete_published_bsync_member_pages',
            'delete_others_bsync_member_pages',
            'edit_private_bsync_member_pages',
            'edit_published_bsync_member_pages',
        );

        foreach ( $cpt_caps as $cap ) {
            $admin_role->add_cap( $cap );
        }
    }

    // Ensure rewrite rules know about the member page URLs.
    bsync_member_register_cpt_and_tax();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bsync_member_activate' );

/**
 * Register custom post type and taxonomy.
 */
function bsync_member_register_cpt_and_tax() {
    // Admin-configurable labels (fall back to defaults).
    $page_type_label      = get_option( 'bsync_member_page_type_label', __( 'Member Page', 'bsync-member' ) );
    $page_type_label_plural = get_option( 'bsync_member_page_type_label_plural', __( 'Member Pages', 'bsync-member' ) );

    $category_label       = get_option( 'bsync_member_category_type_label', __( 'Member Category', 'bsync-member' ) );
    $category_label_plural = get_option( 'bsync_member_category_type_label_plural', __( 'Member Categories', 'bsync-member' ) );

    // Custom post type for private member pages.
    $labels = array(
        'name'                  => $page_type_label_plural,
        'singular_name'         => $page_type_label,
        'add_new'               => __( 'Add New', 'bsync-member' ),
        'add_new_item'          => sprintf( __( 'Add New %s', 'bsync-member' ), $page_type_label ),
        'edit_item'             => sprintf( __( 'Edit %s', 'bsync-member' ), $page_type_label ),
        'new_item'              => sprintf( __( 'New %s', 'bsync-member' ), $page_type_label ),
        'view_item'             => sprintf( __( 'View %s', 'bsync-member' ), $page_type_label ),
        'search_items'          => sprintf( __( 'Search %s', 'bsync-member' ), $page_type_label_plural ),
        'not_found'             => __( 'No items found.', 'bsync-member' ),
        'not_found_in_trash'    => __( 'No items found in Trash.', 'bsync-member' ),
        'all_items'             => $page_type_label_plural,
        'menu_name'             => $page_type_label_plural,
    );

    $cpt_args = array(
        'labels'             => $labels,
        // Not publicly visible to non-members, but has real front-end URLs.
        'public'             => false,
        'exclude_from_search'=> true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        // Nest member pages under the main "Bsync Members" admin menu.
        'show_in_menu'       => 'bsync_members',
        'hierarchical'       => true,
        'supports'           => array( 'title', 'editor', 'author', 'revisions' ),
        'capability_type'    => array( 'bsync_member_page', 'bsync_member_pages' ),
        'map_meta_cap'       => true,
        'rewrite'            => array(
            'slug'       => 'member-page',
            'with_front' => false,
        ),
    );

    register_post_type( BSYNC_MEMBER_PAGE_CPT, $cpt_args );

    // Custom taxonomy for grouping member pages.
    $tax_labels = array(
        'name'              => $category_label_plural,
        'singular_name'     => $category_label,
        'search_items'      => sprintf( __( 'Search %s', 'bsync-member' ), $category_label_plural ),
        'all_items'         => sprintf( __( 'All %s', 'bsync-member' ), $category_label_plural ),
        'edit_item'         => sprintf( __( 'Edit %s', 'bsync-member' ), $category_label ),
        'update_item'       => sprintf( __( 'Update %s', 'bsync-member' ), $category_label ),
        'add_new_item'      => sprintf( __( 'Add New %s', 'bsync-member' ), $category_label ),
        'new_item_name'     => sprintf( __( 'New %s Name', 'bsync-member' ), $category_label ),
        'menu_name'         => $category_label_plural,
    );

    $tax_args = array(
        'hierarchical'      => true,
        'labels'            => $tax_labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'public'            => false,
        'publicly_queryable'=> true,
        'query_var'         => 'bsync_member_category',
        'show_in_quick_edit'=> true,
        'rewrite'           => array(
            'slug'       => 'member-category',
            'with_front' => false,
        ),
        'capabilities'      => array(
            'manage_terms'  => BSYNC_MEMBER_MANAGE_CAP,
            'edit_terms'    => BSYNC_MEMBER_MANAGE_CAP,
            'delete_terms'  => BSYNC_MEMBER_MANAGE_CAP,
            'assign_terms'  => BSYNC_MEMBER_MANAGE_CAP,
        ),
    );

    register_taxonomy( BSYNC_MEMBER_CATEGORY_TAX, array( BSYNC_MEMBER_PAGE_CPT ), $tax_args );
}
add_action( 'init', 'bsync_member_register_cpt_and_tax' );

/**
 * Ensure the given role has capabilities for managing the Member Page CPT.
 *
 * This is called on activation but can safely be called at runtime as well.
 */
function bsync_member_add_cpt_caps_to_role( $role_slug ) {
    $role = get_role( $role_slug );
    if ( ! $role ) {
        return;
    }

    // General member management capability.
    $role->add_cap( BSYNC_MEMBER_MANAGE_CAP );

    // CPT meta caps for bsync_member_page.
    $caps = array(
        'edit_bsync_member_page',
        'read_bsync_member_page',
        'delete_bsync_member_page',
        'edit_bsync_member_pages',
        'edit_others_bsync_member_pages',
        'publish_bsync_member_pages',
        'read_private_bsync_member_pages',
        'delete_bsync_member_pages',
        'delete_private_bsync_member_pages',
        'delete_published_bsync_member_pages',
        'delete_others_bsync_member_pages',
        'edit_private_bsync_member_pages',
        'edit_published_bsync_member_pages',
    );

    foreach ( $caps as $cap ) {
        $role->add_cap( $cap );
    }
}

/**
 * On plugin load, make sure roles have the right capabilities.
 * This helps recover if roles were edited externally.
 */
function bsync_member_ensure_caps() {
    bsync_member_add_cpt_caps_to_role( BSYNC_MEMBER_MANAGER_ROLE );
    // Ensure front-end portal capability stays attached.
    $manager_role = get_role( BSYNC_MEMBER_MANAGER_ROLE );
    if ( $manager_role ) {
        $manager_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );
        $manager_role->add_cap( 'list_users' );
        $manager_role->add_cap( 'edit_users' );
        $manager_role->add_cap( 'promote_users' );
        $manager_role->add_cap( 'create_users' );
    }

    $member_role = get_role( BSYNC_MEMBER_ROLE );
    if ( $member_role ) {
        $member_role->add_cap( 'read' );
        $member_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );
    }

    // Keep administrator in sync so they always see the menu and pages.
    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $admin_role->add_cap( BSYNC_MEMBER_MANAGE_CAP );
        $admin_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );
        bsync_member_add_cpt_caps_to_role( 'administrator' );
    }
}
add_action( 'init', 'bsync_member_ensure_caps', 20 );

/**
 * Limit which roles a Member Manager can assign when creating or editing users.
 *
 * Member Managers should be able to set users to the Member role, but not to
 * higher-privileged roles such as Administrator or even the Member Manager
 * role itself.
 */
function bsync_member_limit_editable_roles_for_manager( $roles ) {
    // Only affect users who manage members but are not administrators.
    if ( ! current_user_can( BSYNC_MEMBER_MANAGE_CAP ) || current_user_can( 'administrator' ) ) {
        return $roles;
    }

    $allowed = array();

    foreach ( $roles as $role_key => $details ) {
        if ( BSYNC_MEMBER_ROLE === $role_key ) {
            $allowed[ $role_key ] = $details;
        }
    }

    return $allowed;
}
add_filter( 'editable_roles', 'bsync_member_limit_editable_roles_for_manager' );

/**
 * Prevent Member Managers from editing other users who have any manager capabilities.
 *
 * This ensures member managers can only edit regular members, not other managers.
 */
function bsync_member_prevent_manager_editing_managers( $caps, $cap, $user_id, $args ) {
    // Only apply to edit_user capability checks.
    if ( 'edit_user' !== $cap ) {
        return $caps;
    }

    // Administrators are unrestricted.
    if ( user_can( $user_id, 'administrator' ) ) {
        return $caps;
    }

    // Only affect users who have member management capability.
    if ( ! user_can( $user_id, BSYNC_MEMBER_MANAGE_CAP ) ) {
        return $caps;
    }

    // Get the target user being edited.
    if ( ! isset( $args[0] ) ) {
        return $caps;
    }

    $target_user_id = (int) $args[0];
    $target_user = get_user_by( 'id', $target_user_id );
    
    if ( ! $target_user ) {
        return $caps;
    }

    // Check if the target user has any manager capabilities (is a manager).
    $target_roles = (array) $target_user->roles;
    $manager_groups = bsync_member_get_manager_groups();
    
    // Check if target has base member manager role.
    if ( in_array( BSYNC_MEMBER_MANAGER_ROLE, $target_roles, true ) ) {
        // Prevent editing by adding a capability they don't have.
        return array( 'do_not_allow' );
    }
    
    // Check if target has any of the dynamic manager group roles.
    foreach ( $manager_groups as $slug => $label ) {
        $role_name = 'bsync_manager_' . $slug;
        if ( in_array( $role_name, $target_roles, true ) ) {
            return array( 'do_not_allow' );
        }
    }

    return $caps;
}
add_filter( 'map_meta_cap', 'bsync_member_prevent_manager_editing_managers', 10, 4 );

/**
 * Admin menu: Bsync Members (Members list + Settings).
 */
function bsync_member_register_admin_menu() {
    add_menu_page(
        __( 'Bsync Members', 'bsync-member' ),
        __( 'Bsync Members', 'bsync-member' ),
        BSYNC_MEMBER_MANAGE_CAP,
        'bsync_members',
        'bsync_member_render_members_page',
        'dashicons-groups',
        27
    );

    add_submenu_page(
        'bsync_members',
        __( 'Members', 'bsync-member' ),
        __( 'Members', 'bsync-member' ),
        BSYNC_MEMBER_MANAGE_CAP,
        'bsync_members',
        'bsync_member_render_members_page'
    );

    add_submenu_page(
        'bsync_members',
        __( 'Settings', 'bsync-member' ),
        __( 'Settings', 'bsync-member' ),
        // Only site admins (manage_options) should see Settings.
        'manage_options',
        'bsync_member_settings',
        'bsync_member_render_settings_page'
    );

    add_submenu_page(
        'bsync_members',
        __( 'How It Works', 'bsync-member' ),
        __( 'How It Works', 'bsync-member' ),
        BSYNC_MEMBER_MANAGE_CAP,
        'bsync_member_how_it_works',
        'bsync_member_render_how_it_works_page'
    );

    // Advanced configuration pages for roles and page types (admin only).
    add_submenu_page(
        'bsync_members',
        __( 'Member Roles', 'bsync-member' ),
        __( 'Member Roles', 'bsync-member' ),
        'manage_options',
        'bsync_member_roles',
        'bsync_member_render_roles_page'
    );

    add_submenu_page(
        'bsync_members',
        __( 'Page Types', 'bsync-member' ),
        __( 'Page Types', 'bsync-member' ),
        'manage_options',
        'bsync_member_page_types',
        'bsync_member_render_page_types_page'
    );
}
add_action( 'admin_menu', 'bsync_member_register_admin_menu' );

/**
 * Settings screen: rename roles, page type, and category type.
 */
function bsync_member_render_settings_page() {
    // Restrict this screen to administrators or similar high-privilege roles.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'bsync-member' ) );
    }

    $notice = '';

    if ( ! empty( $_POST['bsync_member_settings_nonce'] ) && wp_verify_nonce( $_POST['bsync_member_settings_nonce'], 'bsync_member_save_settings' ) ) {
        $manager_label        = isset( $_POST['bsync_member_manager_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_manager_label'] ) ) : '';
        $member_label         = isset( $_POST['bsync_member_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_label'] ) ) : '';
        $page_label           = isset( $_POST['bsync_member_page_type_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_page_type_label'] ) ) : '';
        $page_label_plural    = isset( $_POST['bsync_member_page_type_label_plural'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_page_type_label_plural'] ) ) : '';
        $category_label       = isset( $_POST['bsync_member_category_type_label'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_category_type_label'] ) ) : '';
        $category_label_plural = isset( $_POST['bsync_member_category_type_label_plural'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_category_type_label_plural'] ) ) : '';

        if ( $manager_label ) {
            update_option( 'bsync_member_manager_label', $manager_label );
        }
        if ( $member_label ) {
            update_option( 'bsync_member_label', $member_label );
        }
        if ( $page_label ) {
            update_option( 'bsync_member_page_type_label', $page_label );
        }
        if ( $page_label_plural ) {
            update_option( 'bsync_member_page_type_label_plural', $page_label_plural );
        }
        if ( $category_label ) {
            update_option( 'bsync_member_category_type_label', $category_label );
        }
        if ( $category_label_plural ) {
            update_option( 'bsync_member_category_type_label_plural', $category_label_plural );
        }

        // Update role display names so they reflect the new labels in the UI.
        global $wp_roles;
        if ( isset( $wp_roles->roles[ BSYNC_MEMBER_MANAGER_ROLE ] ) && $manager_label ) {
            $wp_roles->roles[ BSYNC_MEMBER_MANAGER_ROLE ]['name'] = $manager_label;
            $wp_roles->role_names[ BSYNC_MEMBER_MANAGER_ROLE ]    = $manager_label;
        }
        if ( isset( $wp_roles->roles[ BSYNC_MEMBER_ROLE ] ) && $member_label ) {
            $wp_roles->roles[ BSYNC_MEMBER_ROLE ]['name'] = $member_label;
            $wp_roles->role_names[ BSYNC_MEMBER_ROLE ]    = $member_label;
        }

        $notice = __( 'Settings saved.', 'bsync-member' );
    }

    $manager_label         = get_option( 'bsync_member_manager_label', __( 'Member Manager', 'bsync-member' ) );
    $member_label          = get_option( 'bsync_member_label', __( 'Member', 'bsync-member' ) );
    $page_label            = get_option( 'bsync_member_page_type_label', __( 'Member Page', 'bsync-member' ) );
    $page_label_plural     = get_option( 'bsync_member_page_type_label_plural', __( 'Member Pages', 'bsync-member' ) );
    $category_label        = get_option( 'bsync_member_category_type_label', __( 'Member Category', 'bsync-member' ) );
    $category_label_plural = get_option( 'bsync_member_category_type_label_plural', __( 'Member Categories', 'bsync-member' ) );

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Bsync Members Settings', 'bsync-member' ) . '</h1>';

    if ( $notice ) {
        echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field( 'bsync_member_save_settings', 'bsync_member_settings_nonce' );

    echo '<table class="form-table" role="presentation">';

    echo '<tr><th scope="row"><label for="bsync_member_manager_label">' . esc_html__( 'Member Manager role label', 'bsync-member' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text" name="bsync_member_manager_label" id="bsync_member_manager_label" value="%s" />', esc_attr( $manager_label ) );
    echo '<p class="description">' . esc_html__( 'Display name for the Member Manager role.', 'bsync-member' ) . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_label">' . esc_html__( 'Member role label', 'bsync-member' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text" name="bsync_member_label" id="bsync_member_label" value="%s" />', esc_attr( $member_label ) );
    echo '<p class="description">' . esc_html__( 'Display name for the Member role.', 'bsync-member' ) . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_page_type_label">' . esc_html__( 'Member page type (singular)', 'bsync-member' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text" name="bsync_member_page_type_label" id="bsync_member_page_type_label" value="%s" />', esc_attr( $page_label ) );
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_page_type_label_plural">' . esc_html__( 'Member page type (plural)', 'bsync-member' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text" name="bsync_member_page_type_label_plural" id="bsync_member_page_type_label_plural" value="%s" />', esc_attr( $page_label_plural ) );
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_category_type_label">' . esc_html__( 'Member category type (singular)', 'bsync-member' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text" name="bsync_member_category_type_label" id="bsync_member_category_type_label" value="%s" />', esc_attr( $category_label ) );
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_category_type_label_plural">' . esc_html__( 'Member category type (plural)', 'bsync-member' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text" name="bsync_member_category_type_label_plural" id="bsync_member_category_type_label_plural" value="%s" />', esc_attr( $category_label_plural ) );
    echo '</td></tr>';

    echo '</table>';

    submit_button( __( 'Save Changes', 'bsync-member' ) );
    echo '</form>';
    echo '</div>';
}

/**
 * Member Manager UI: list, add, deactivate, delete, reset password.
 */
function bsync_member_render_members_page() {
    if ( ! current_user_can( BSYNC_MEMBER_MANAGE_CAP ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'bsync-member' ) );
    }

    $notice = '';
    $error  = '';

    // Handle add member form submission.
    if ( ! empty( $_POST['bsync_member_add_nonce'] ) && wp_verify_nonce( $_POST['bsync_member_add_nonce'], 'bsync_member_add_member' ) ) {
        $email      = isset( $_POST['bsync_member_email'] ) ? sanitize_email( wp_unslash( $_POST['bsync_member_email'] ) ) : '';
        $first_name = isset( $_POST['bsync_member_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_first_name'] ) ) : '';
        $last_name  = isset( $_POST['bsync_member_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bsync_member_last_name'] ) ) : '';
        $send_email = ! empty( $_POST['bsync_member_send_email'] );

        if ( ! $email ) {
            $error = __( 'Please provide a valid email address.', 'bsync-member' );
        } else {
            $existing = get_user_by( 'email', $email );

            if ( $existing ) {
                $existing->add_role( BSYNC_MEMBER_ROLE );
                update_user_meta( $existing->ID, 'bsync_member_active', 1 );
                $notice = __( 'Existing user updated to Member.', 'bsync-member' );
            } else {
                // Generate a username from email prefix, ensure uniqueness.
                $username_base = sanitize_user( current( explode( '@', $email ) ), true );
                if ( ! $username_base ) {
                    $username_base = 'bsync_member';
                }

                $username = $username_base;
                $suffix   = 1;
                while ( username_exists( $username ) ) {
                    $username = $username_base . $suffix;
                    $suffix++;
                }

                $password = wp_generate_password( 12, false );

                $user_id = wp_insert_user(
                    array(
                        'user_login' => $username,
                        'user_pass'  => $password,
                        'user_email' => $email,
                        'first_name' => $first_name,
                        'last_name'  => $last_name,
                        'role'       => BSYNC_MEMBER_ROLE,
                    )
                );

                if ( is_wp_error( $user_id ) ) {
                    $error = $user_id->get_error_message();
                } else {
                    update_user_meta( $user_id, 'bsync_member_active', 1 );
                    $notice = __( 'New Member created.', 'bsync-member' );

                    if ( $send_email ) {
                        // Send the standard WP new user notification to the user.
                        if ( function_exists( 'wp_send_new_user_notifications' ) ) {
                            wp_send_new_user_notifications( $user_id, 'user' );
                        }
                    }
                }
            }
        }
    }

    // Handle member row actions (deactivate, activate, delete, reset password).
    if ( isset( $_GET['action'], $_GET['user_id'], $_GET['_wpnonce'] ) ) {
        $action  = sanitize_key( wp_unslash( $_GET['action'] ) );
        $user_id = absint( $_GET['user_id'] );

        if ( $user_id && wp_verify_nonce( $_GET['_wpnonce'], 'bsync_member_manage_member_' . $user_id ) ) {
            $user = get_user_by( 'id', $user_id );
            if ( $user ) {
                switch ( $action ) {
                    case 'deactivate':
                        $user->remove_role( BSYNC_MEMBER_ROLE );
                        update_user_meta( $user_id, 'bsync_member_active', 0 );
                        $notice = __( 'Member deactivated.', 'bsync-member' );
                        break;
                    case 'activate':
                        $user->add_role( BSYNC_MEMBER_ROLE );
                        update_user_meta( $user_id, 'bsync_member_active', 1 );
                        $notice = __( 'Member activated.', 'bsync-member' );
                        break;
                    case 'delete':
                        require_once ABSPATH . 'wp-admin/includes/user.php';
                        wp_delete_user( $user_id );
                        $notice = __( 'Member deleted.', 'bsync-member' );
                        break;
                    case 'reset_password':
                        if ( function_exists( 'retrieve_password' ) ) {
                            $result = retrieve_password( $user->user_login );
                            if ( is_wp_error( $result ) ) {
                                $error = $result->get_error_message();
                            } else {
                                $notice = __( 'Password reset email sent.', 'bsync-member' );
                            }
                        }
                        break;
                }
            }
        }
    }

    // Determine which member types this viewer is allowed to see.
    $allowed_member_types = bsync_member_get_allowed_member_types_for_current_user();

    // Fetch members that are managed by this plugin.
    // We look for users who have the bsync_member_active meta flag so that
    // deactivated members (who may no longer have the Member role) still
    // appear in this table and can be reactivated later.
    $meta_query = array(
        array(
            'key'     => 'bsync_member_active',
            'compare' => 'EXISTS',
        ),
    );

    if ( ! empty( $allowed_member_types ) ) {
        $meta_query[] = array(
            'key'     => 'bsync_member_type',
            'value'   => array_values( $allowed_member_types ),
            'compare' => 'IN',
        );
    }

    $user_query = new WP_User_Query(
        array(
            'number'     => 200,
            'meta_query' => $meta_query,
        )
    );
    $members = $user_query->get_results();

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Bsync Members', 'bsync-member' ) . '</h1>';

    if ( $notice ) {
        echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
    }
    if ( $error ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
    }

    // Show the configured role labels so managers can see how their role is named.
    $manager_label = get_option( 'bsync_member_manager_label', __( 'Member Manager', 'bsync-member' ) );
    $member_label  = get_option( 'bsync_member_label', __( 'Member', 'bsync-member' ) );

    echo '<p><em>' . sprintf(
        /* translators: 1: Member Manager role label, 2: Member role label */
        esc_html__( 'Role labels: %1$s (manager) and %2$s (member).', 'bsync-member' ),
        esc_html( $manager_label ),
        esc_html( $member_label )
    ) . '</em></p>';

    echo '<h2>' . esc_html__( 'Add New Member', 'bsync-member' ) . '</h2>';
    echo '<form method="post" style="max-width:600px;">';
    wp_nonce_field( 'bsync_member_add_member', 'bsync_member_add_nonce' );

    echo '<table class="form-table" role="presentation">';

    echo '<tr><th scope="row"><label for="bsync_member_email">' . esc_html__( 'Email', 'bsync-member' ) . '</label></th><td>';
    echo '<input type="email" required class="regular-text" name="bsync_member_email" id="bsync_member_email" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_first_name">' . esc_html__( 'First Name', 'bsync-member' ) . '</label></th><td>';
    echo '<input type="text" class="regular-text" name="bsync_member_first_name" id="bsync_member_first_name" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="bsync_member_last_name">' . esc_html__( 'Last Name', 'bsync-member' ) . '</label></th><td>';
    echo '<input type="text" class="regular-text" name="bsync_member_last_name" id="bsync_member_last_name" />';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . esc_html__( 'Email login link', 'bsync-member' ) . '</th><td>';
    echo '<label><input type="checkbox" name="bsync_member_send_email" value="1" /> ' . esc_html__( 'Email the user a login link / password setup.', 'bsync-member' ) . '</label>';
    echo '</td></tr>';

    echo '</table>';

    submit_button( __( 'Add Member', 'bsync-member' ) );
    echo '</form>';

    echo '<h2 style="margin-top:40px;">' . esc_html__( 'Existing Members', 'bsync-member' ) . '</h2>';

    // Admins can filter by member type; managers are limited to their mapped types.
    $all_member_types = bsync_member_get_member_types();
    if ( current_user_can( 'manage_options' ) && ! empty( $all_member_types ) ) {
        $current_filter = isset( $_GET['bsync_member_member_type'] ) ? sanitize_key( wp_unslash( $_GET['bsync_member_member_type'] ) ) : '';
        echo '<form method="get" style="margin-bottom:10px;">';
        echo '<input type="hidden" name="page" value="bsync_members" />';
        echo '<label for="bsync_member_member_type_filter">' . esc_html__( 'Filter by member type:', 'bsync-member' ) . '</label> ';
        echo '<select name="bsync_member_member_type" id="bsync_member_member_type_filter">';
        echo '<option value="">' . esc_html__( 'All member types', 'bsync-member' ) . '</option>';
        foreach ( $all_member_types as $slug => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $slug ),
                selected( $current_filter, $slug, false ),
                esc_html( $label )
            );
        }
        echo '</select> ';
        submit_button( __( 'Apply', 'bsync-member' ), 'secondary', '', false );
        echo '</form>';
    } elseif ( current_user_can( BSYNC_MEMBER_MANAGE_CAP ) && ! current_user_can( 'manage_options' ) ) {
        // For non-admin managers, show a short note indicating which member types they can see.
        $manager_group = get_user_meta( get_current_user_id(), 'bsync_member_manager_group', true );
        $manager_groups = bsync_member_get_manager_groups();
        $manager_label  = isset( $manager_groups[ $manager_group ] ) ? $manager_groups[ $manager_group ] : '';
        if ( ! empty( $allowed_member_types ) ) {
            $type_labels = array();
            foreach ( $allowed_member_types as $slug ) {
                if ( isset( $all_member_types[ $slug ] ) ) {
                    $type_labels[] = $all_member_types[ $slug ];
                }
            }
            if ( ! empty( $type_labels ) ) {
                echo '<p><em>' . esc_html( sprintf( __( 'You are viewing members for %1$s (%2$s).', 'bsync-member' ), $manager_label ? $manager_label : __( 'your manager group', 'bsync-member' ), implode( ', ', $type_labels ) ) ) . '</em></p>';
            }
        }
    }

    if ( ! empty( $members ) ) {
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'bsync-member' ) . '</th>';
        echo '<th>' . esc_html__( 'Email', 'bsync-member' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'bsync-member' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'bsync-member' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $members as $member ) {
            $user_id   = $member->ID;
            $active    = (int) get_user_meta( $user_id, 'bsync_member_active', true );
            $is_active = $active === 1 || in_array( BSYNC_MEMBER_ROLE, (array) $member->roles, true );

            $base_url = add_query_arg(
                array(
                    'page' => 'bsync_members',
                ),
                admin_url( 'admin.php' )
            );

            $deactivate_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'deactivate',
                        'user_id' => $user_id,
                    ),
                    $base_url
                ),
                'bsync_member_manage_member_' . $user_id
            );

            $activate_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'activate',
                        'user_id' => $user_id,
                    ),
                    $base_url
                ),
                'bsync_member_manage_member_' . $user_id
            );

            $delete_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'delete',
                        'user_id' => $user_id,
                    ),
                    $base_url
                ),
                'bsync_member_manage_member_' . $user_id
            );

            $reset_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'  => 'reset_password',
                        'user_id' => $user_id,
                    ),
                    $base_url
                ),
                'bsync_member_manage_member_' . $user_id
            );

            echo '<tr>';
            echo '<td>' . esc_html( $member->display_name ) . '</td>';
            echo '<td><a href="mailto:' . esc_attr( $member->user_email ) . '">' . esc_html( $member->user_email ) . '</a></td>';
            echo '<td>' . ( $is_active ? esc_html__( 'Active', 'bsync-member' ) : esc_html__( 'Inactive', 'bsync-member' ) ) . '</td>';
            echo '<td>';
            if ( $is_active ) {
                echo '<a href="' . esc_url( $deactivate_url ) . '">' . esc_html__( 'Deactivate', 'bsync-member' ) . '</a> | ';
            } else {
                echo '<a href="' . esc_url( $activate_url ) . '">' . esc_html__( 'Activate', 'bsync-member' ) . '</a> | ';
            }
            echo '<a href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Send reset', 'bsync-member' ) . '</a> | ';
            echo '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this member? This cannot be undone.', 'bsync-member' ) ) . '\');">' . esc_html__( 'Delete', 'bsync-member' ) . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__( 'No members found yet.', 'bsync-member' ) . '</p>';
    }

    echo '</div>';
}

/**
 * Render "How It Works" instructions from a static HTML file.
 */
function bsync_member_render_how_it_works_page() {
    if ( ! current_user_can( BSYNC_MEMBER_MANAGE_CAP ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'bsync-member' ) );
    }

    $file = BSYNC_MEMBER_PATH . 'how-it-works.html';

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Bsync Member – How It Works', 'bsync-member' ) . '</h1>';

    if ( file_exists( $file ) ) {
        // The HTML file is static and maintained as safe instructions.
        readfile( $file );
    } else {
        echo '<p>' . esc_html__( 'Instructions file not found.', 'bsync-member' ) . '</p>';
    }

    echo '</div>';
}

/**
 * Front-end protection: only logged-in members (or managers/admins with portal
 * access) can view member pages and member category archives. The pages still
 * have real URLs, so once logged in any member can visit them directly.
 */
function bsync_member_protect_member_pages() {
    // First, handle regular WordPress pages that have Bsync Member visibility settings.
    if ( is_page() ) {
        global $post;
        if ( $post instanceof WP_Post ) {
            $visibility = get_post_meta( $post->ID, 'bsync_member_page_visibility', true );
            if ( ! $visibility ) {
                $visibility = 'public';
            }

            if ( 'public' !== $visibility ) {
                // Require a logged-in user with portal access.
                if ( ! is_user_logged_in() || ! current_user_can( BSYNC_MEMBER_PORTAL_CAP ) ) {
                    $redirect = get_permalink( $post );
                    wp_safe_redirect( wp_login_url( $redirect ) );
                    exit;
                }

                if ( 'member_types' === $visibility ) {
                    $allowed_types = get_post_meta( $post->ID, 'bsync_member_page_member_types', true );
                    if ( ! is_array( $allowed_types ) ) {
                        $allowed_types = array();
                    }
                    $allowed_types = array_values( array_filter( array_map( 'sanitize_key', $allowed_types ) ) );

                    if ( ! empty( $allowed_types ) ) {
                        $user_type = get_user_meta( get_current_user_id(), 'bsync_member_type', true );
                        $user_type = sanitize_key( $user_type );

                        if ( ! $user_type || ! in_array( $user_type, $allowed_types, true ) ) {
                            // User is logged in but not allowed to view this page; show 404.
                            global $wp_query;
                            $wp_query->set_404();
                            status_header( 404 );
                            return;
                        }
                    }
                }
            }
        }
    }

    // Then, enforce protection on member pages and member category archives.
    if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) || is_tax( BSYNC_MEMBER_CATEGORY_TAX ) ) {
        if ( ! is_user_logged_in() || ! current_user_can( BSYNC_MEMBER_PORTAL_CAP ) ) {
            // Redirect to login and then back to the requested member page.
            if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) ) {
                $redirect = get_permalink();
            } elseif ( is_tax( BSYNC_MEMBER_CATEGORY_TAX ) ) {
                $term     = get_queried_object();
                $redirect = ! empty( $term ) ? get_term_link( $term ) : home_url( '/' );
            } else {
                $redirect = home_url( '/' );
            }
            wp_safe_redirect( wp_login_url( $redirect ) );
            exit;
        }

        // For individual member pages, optionally further restrict by member types
        // using the same page-level visibility meta as regular pages.
        if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) ) {
            global $post;
            if ( $post instanceof WP_Post ) {
                $visibility = get_post_meta( $post->ID, 'bsync_member_page_visibility', true );
                if ( ! $visibility ) {
                    $visibility = 'members'; // default for member pages is members-only.
                }

                if ( 'member_types' === $visibility ) {
                    $allowed_types = get_post_meta( $post->ID, 'bsync_member_page_member_types', true );
                    if ( ! is_array( $allowed_types ) ) {
                        $allowed_types = array();
                    }
                    $allowed_types = array_values( array_filter( array_map( 'sanitize_key', $allowed_types ) ) );

                    if ( ! empty( $allowed_types ) ) {
                        $user_type = get_user_meta( get_current_user_id(), 'bsync_member_type', true );
                        $user_type = sanitize_key( $user_type );

                        if ( ! $user_type || ! in_array( $user_type, $allowed_types, true ) ) {
                            // Logged-in member but not allowed for this member page.
                            global $wp_query;
                            $wp_query->set_404();
                            status_header( 404 );
                            return;
                        }
                    }
                }
            }
        }
    }
}
add_action( 'template_redirect', 'bsync_member_protect_member_pages' );

/**
 * Ensure member pages and member category archives are not indexed by search
 * engines even though they are viewable on the front end for logged-in
 * members.
 */
function bsync_member_noindex_member_pages() {
    if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) || is_tax( BSYNC_MEMBER_CATEGORY_TAX ) ) {
        echo "<meta name='robots' content='noindex,nofollow' />\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
    }
}
add_action( 'wp_head', 'bsync_member_noindex_member_pages' );

/**
 * Hide the WordPress admin bar on the front end for users who either have
 * no role at all or who are regular members created/managed by this plugin.
 *
 * Member Managers and site admins keep the admin bar so they can reach
 * wp-admin and management screens.
 */
function bsync_member_control_admin_bar( $show ) {
    if ( is_admin() || ! is_user_logged_in() ) {
        return $show;
    }

    $user = wp_get_current_user();
    if ( ! $user || ! ( $user instanceof WP_User ) ) {
        return $show;
    }

    $roles = (array) $user->roles;

    // Users with no role should not see the admin bar on the front end.
    if ( empty( $roles ) ) {
        return false;
    }

    // Basic members (bsync_member) who are not managers/admins also do not
    // need the admin bar on the front end.
    if (
        in_array( BSYNC_MEMBER_ROLE, $roles, true ) &&
        ! user_can( $user, BSYNC_MEMBER_MANAGE_CAP ) &&
        ! user_can( $user, 'edit_posts' ) &&
        ! user_can( $user, 'manage_options' )
    ) {
        return false;
    }

    return $show;
}
add_filter( 'show_admin_bar', 'bsync_member_control_admin_bar' );

/**
 * Front-end styles for member pages, member category archives and shortcodes.
 */
function bsync_member_enqueue_frontend_assets() {
    $enqueue = false;

    // Always style the custom CPT and taxonomy archives.
    if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) || is_tax( BSYNC_MEMBER_CATEGORY_TAX ) ) {
        $enqueue = true;
    } elseif ( is_singular() ) {
        // Also load styles on pages using our shortcodes.
        global $post;
        if ( $post instanceof WP_Post ) {
            if (
                has_shortcode( $post->post_content, 'bsync_member_portal' ) ||
                has_shortcode( $post->post_content, 'bsync_member_categories' ) ||
                has_shortcode( $post->post_content, 'bsync_member_login' )
            ) {
                $enqueue = true;
            }
        }
    }

    if ( $enqueue ) {
        wp_enqueue_style(
            'bsync-member-frontend',
            BSYNC_MEMBER_URL . 'assets/css/frontend.css',
            array(),
            BSYNC_MEMBER_VERSION
        );
    }
}
add_action( 'wp_enqueue_scripts', 'bsync_member_enqueue_frontend_assets' );

/**
 * Ensure member category archives query the member page CPT, so assigned
 * member pages actually appear on the archive instead of falling back to
 * normal posts.
 */
function bsync_member_adjust_category_query( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( $query->is_tax( BSYNC_MEMBER_CATEGORY_TAX ) ) {
        $query->set( 'post_type', array( BSYNC_MEMBER_PAGE_CPT ) );
        $query->set( 'post_status', 'publish' );
    }
}
add_action( 'pre_get_posts', 'bsync_member_adjust_category_query' );

/**
 * Fluent Forms integration: link submissions to members and member pages.
 *
 * We hook into several Fluent Forms actions and then look up the submission
 * by ID, so this remains robust even if the hook signatures differ.
 */
function bsync_member_handle_fluent_submission( ...$args ) {
    if ( empty( $args[0] ) ) {
        return;
    }

    $entry_id = (int) $args[0];
    if ( ! $entry_id ) {
        return;
    }

    global $wpdb;

    $submissions_table = $wpdb->prefix . 'fluentform_submissions';
    $entry             = $wpdb->get_row(
        $wpdb->prepare( "SELECT id, form_id, response FROM {$submissions_table} WHERE id = %d", $entry_id ),
        ARRAY_A
    );

    if ( ! $entry ) {
        return;
    }

    $response = array();
    if ( ! empty( $entry['response'] ) ) {
        $decoded = json_decode( $entry['response'], true );
        if ( is_array( $decoded ) ) {
            $response = $decoded;
        }
    }

    $user_id = get_current_user_id();

    // If not logged in, try to map to an existing member by email.
    if ( ! $user_id ) {
        $email = bsync_member_guess_email_from_response( $response );
        if ( $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $user_id = (int) $user->ID;
            }
        }
    }

    if ( ! $user_id ) {
        return; // Cannot associate with a member.
    }

    bsync_member_link_entry_to_member( $entry, $user_id );
}

add_action( 'fluentform_submission_inserted', 'bsync_member_handle_fluent_submission', 10, 5 );
add_action( 'fluentform_after_submission', 'bsync_member_handle_fluent_submission', 10, 3 );
add_action( 'fluentform_submitted', 'bsync_member_handle_fluent_submission', 10, 3 );

/**
 * Try to find an email address within a Fluent Forms response array.
 */
function bsync_member_guess_email_from_response( $response ) {
    if ( ! is_array( $response ) ) {
        return '';
    }

    foreach ( $response as $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $sub ) {
                if ( is_string( $sub ) && strpos( $sub, '@' ) !== false ) {
                    return sanitize_email( $sub );
                }
            }
        } elseif ( is_string( $value ) && strpos( $value, '@' ) !== false ) {
            return sanitize_email( $value );
        }
    }

    return '';
}

/**
 * Persist the relationship: submission -> member user -> member page.
 */
function bsync_member_link_entry_to_member( $entry, $user_id ) {
    if ( empty( $entry['id'] ) || empty( $entry['form_id'] ) ) {
        return;
    }

    global $wpdb;

    $entry_id          = (int) $entry['id'];
    $form_id           = (int) $entry['form_id'];
    $meta_table        = $wpdb->prefix . 'fluentform_submission_meta';
    $now               = current_time( 'mysql' );
    $user_id           = (int) $user_id;

    // Ensure the user has the Member role and is marked active.
    $user = get_user_by( 'id', $user_id );
    if ( $user && ! in_array( BSYNC_MEMBER_ROLE, (array) $user->roles, true ) ) {
        $user->add_role( BSYNC_MEMBER_ROLE );
    }
    update_user_meta( $user_id, 'bsync_member_active', 1 );

    // Link entry to user via meta.
    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$meta_table} WHERE response_id = %d AND form_id = %d AND meta_key = %s",
            $entry_id,
            $form_id,
            'bsync_member_user_id'
        )
    );

    if ( $existing_id ) {
        $wpdb->update(
            $meta_table,
            array(
                'value'      => $user_id,
                'updated_at' => $now,
            ),
            array( 'id' => $existing_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
    } else {
        $wpdb->insert(
            $meta_table,
            array(
                'response_id' => $entry_id,
                'form_id'     => $form_id,
                'meta_key'    => 'bsync_member_user_id',
                'value'       => $user_id,
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
        );
    }

    // Ensure the member has a primary member page and link the entry to it.
    $page_id = (int) get_user_meta( $user_id, 'bsync_member_page_id', true );
    $page    = $page_id ? get_post( $page_id ) : null;

    if ( ! $page || BSYNC_MEMBER_PAGE_CPT !== $page->post_type ) {
        $display_name = $user ? $user->display_name : __( 'Member', 'bsync-member' );
        $page_title   = sprintf( __( "%s's Member Page", 'bsync-member' ), $display_name );

        $page_id = wp_insert_post(
            array(
                'post_type'   => BSYNC_MEMBER_PAGE_CPT,
                'post_status' => 'publish',
                'post_title'  => $page_title,
                'post_author' => $user_id,
            )
        );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_user_meta( $user_id, 'bsync_member_page_id', (int) $page_id );
        }
    }

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        $existing_page_meta = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$meta_table} WHERE response_id = %d AND form_id = %d AND meta_key = %s",
                $entry_id,
                $form_id,
                'bsync_member_page_id'
            )
        );

        if ( $existing_page_meta ) {
            $wpdb->update(
                $meta_table,
                array(
                    'value'      => $page_id,
                    'updated_at' => $now,
                ),
                array( 'id' => $existing_page_meta ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $meta_table,
                array(
                    'response_id' => $entry_id,
                    'form_id'     => $form_id,
                    'meta_key'    => 'bsync_member_page_id',
                    'value'       => $page_id,
                    'status'      => 'active',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
            );
        }
    }
}

/**
 * Fetch submissions linked to a given member.
 */
function bsync_member_get_submissions_for_user( $user_id, $limit = 20 ) {
    global $wpdb;

    $user_id          = (int) $user_id;
    $submissions_table = $wpdb->prefix . 'fluentform_submissions';
    $meta_table        = $wpdb->prefix . 'fluentform_submission_meta';

    $sql = $wpdb->prepare(
        "SELECT s.id, s.form_id, s.created_at, s.response
         FROM {$submissions_table} s
         INNER JOIN {$meta_table} m
            ON m.response_id = s.id
            AND m.form_id = s.form_id
         WHERE m.meta_key = %s
           AND m.value = %d
         ORDER BY s.created_at DESC
         LIMIT %d",
        'bsync_member_user_id',
        $user_id,
        $limit
    );

    $rows = $wpdb->get_results( $sql, ARRAY_A );

    return $rows ? $rows : array();
}

/**
 * Front-end member portal shortcode.
 *
 * Usage: [bsync_member_portal]
 */
function bsync_member_portal_shortcode() {
    if ( ! is_user_logged_in() ) {
        $login_url = wp_login_url( get_permalink() );
        return '<p>' . sprintf(
            /* translators: %s: login URL */
            esc_html__( 'You must be logged in to access the member portal. %s', 'bsync-member' ),
            '<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Log in', 'bsync-member' ) . '</a>'
        ) . '</p>';
    }

    if ( ! current_user_can( BSYNC_MEMBER_PORTAL_CAP ) ) {
        $home_url = home_url( '/' );
        return '<p>' . sprintf(
            /* translators: %s: home URL */
            esc_html__( 'You do not have access to the member portal. %s', 'bsync-member' ),
            '<a href="' . esc_url( $home_url ) . '">' . esc_html__( 'Return to the main site.', 'bsync-member' ) . '</a>'
        ) . '</p>';
    }

    $user_id = get_current_user_id();
    $user    = get_user_by( 'id', $user_id );

    $page_id = (int) get_user_meta( $user_id, 'bsync_member_page_id', true );
    $page    = $page_id ? get_post( $page_id ) : null;

    $submissions = bsync_member_get_submissions_for_user( $user_id, 50 );

    ob_start();

    echo '<div class="bsync-member-portal">';
    echo '<h2>' . esc_html__( 'Member Portal', 'bsync-member' ) . '</h2>';
    echo '<p>' . sprintf( esc_html__( 'Welcome, %s', 'bsync-member' ), esc_html( $user->display_name ) ) . '</p>';

    $logout_url = wp_logout_url( home_url( '/' ) );
    $reset_url  = wp_lostpassword_url();
    echo '<p class="bsync-member-account-links">';
    echo '<a href="' . esc_url( $logout_url ) . '">' . esc_html__( 'Log out', 'bsync-member' ) . '</a>';
    echo ' &middot; ';
    echo '<a href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset password', 'bsync-member' ) . '</a>';
    echo '</p>';

    // Member page section.
    echo '<div class="bsync-member-section bsync-member-page">';
    echo '<h3>' . esc_html__( 'My Member Page', 'bsync-member' ) . '</h3>';
    if ( $page && BSYNC_MEMBER_PAGE_CPT === $page->post_type ) {
        echo '<h4>' . esc_html( get_the_title( $page ) ) . '</h4>';
        echo '<div class="bsync-member-page-content">' . apply_filters( 'the_content', $page->post_content ) . '</div>';
    } else {
        echo '<p>' . esc_html__( 'Your member page has not been created yet.', 'bsync-member' ) . '</p>';
    }
    echo '</div>';

    // Submissions section.
    echo '<div class="bsync-member-section bsync-member-submissions">';
    echo '<h3>' . esc_html__( 'My Form Submissions', 'bsync-member' ) . '</h3>';

    if ( $submissions ) {
        // Collect form IDs to look up titles in one query.
        $form_ids = array();
        foreach ( $submissions as $submission ) {
            $form_ids[] = (int) $submission['form_id'];
        }
        $form_ids = array_values( array_unique( $form_ids ) );

        $form_titles = array();
        if ( $form_ids ) {
            global $wpdb;
            $forms_table = $wpdb->prefix . 'fluentform_forms';
            $placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
            $sql          = $wpdb->prepare(
                "SELECT id, title FROM {$forms_table} WHERE id IN ($placeholders)",
                $form_ids
            );
            $rows         = $wpdb->get_results( $sql, ARRAY_A );
            if ( $rows ) {
                foreach ( $rows as $row ) {
                    $form_titles[ (int) $row['id'] ] = $row['title'];
                }
            }
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Form', 'bsync-member' ) . '</th>';
        echo '<th>' . esc_html__( 'Submitted', 'bsync-member' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $submissions as $submission ) {
            $form_id    = (int) $submission['form_id'];
            $form_title = isset( $form_titles[ $form_id ] ) ? $form_titles[ $form_id ] : sprintf( __( 'Form #%d', 'bsync-member' ), $form_id );

            echo '<tr>';
            echo '<td>' . esc_html( $form_title ) . '</td>';
            echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission['created_at'] ) ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__( 'You have not submitted any forms yet.', 'bsync-member' ) . '</p>';
    }

    echo '</div>'; // submissions section.

    echo '</div>'; // portal container.

    return ob_get_clean();
}
add_shortcode( 'bsync_member_portal', 'bsync_member_portal_shortcode' );

/**
 * Shortcode to list all member categories with links to their archives.
 *
 * Usage: [bsync_member_categories]
 */
function bsync_member_categories_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>' . esc_html__( 'You must be logged in to view member categories.', 'bsync-member' ) . '</p>';
    }

    if ( ! current_user_can( BSYNC_MEMBER_PORTAL_CAP ) ) {
        return '<p>' . esc_html__( 'You do not have access to member categories.', 'bsync-member' ) . '</p>';
    }

    $terms = get_terms(
        array(
            'taxonomy'   => BSYNC_MEMBER_CATEGORY_TAX,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        )
    );

    ob_start();

    echo '<div class="bsync-member-category-list">';
    echo '<h2>' . esc_html__( 'Member Categories', 'bsync-member' ) . '</h2>';

    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        echo '<ul class="bsync-member-category-list-items">';
        foreach ( $terms as $term ) {
            $link = get_term_link( $term );
            if ( is_wp_error( $link ) ) {
                continue;
            }

            echo '<li class="bsync-member-category-list-item">';
            echo '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>';
            echo '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__( 'No member categories found yet.', 'bsync-member' ) . '</p>';
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode( 'bsync_member_categories', 'bsync_member_categories_shortcode' );

/**
 * Styled member login form shortcode.
 *
 * Usage: [bsync_member_login] or [bsync_member_login redirect="/member-portal/"]
 */
function bsync_member_login_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'redirect' => '',
        ),
        $atts,
        'bsync_member_login'
    );

    $redirect_to = '';
    if ( ! empty( $atts['redirect'] ) ) {
        $redirect_to = trim( $atts['redirect'] );
        if ( 0 === strpos( $redirect_to, '/' ) ) {
            $redirect_to = home_url( $redirect_to );
        }
    } elseif ( ! empty( $_GET['redirect_to'] ) ) {
        $redirect_to = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) );
    }

    if ( is_user_logged_in() ) {
        if ( $redirect_to ) {
            return '<p>' . sprintf(
                /* translators: %s: redirect URL */
                esc_html__( 'You are already logged in. %s', 'bsync-member' ),
                '<a href="' . esc_url( $redirect_to ) . '">' . esc_html__( 'Continue', 'bsync-member' ) . '</a>'
            ) . '</p>';
        }

        return '<p>' . esc_html__( 'You are already logged in.', 'bsync-member' ) . '</p>';
    }

    ob_start();

    echo '<div class="bsync-member-login">';
    echo '<h2>' . esc_html__( 'Member Login', 'bsync-member' ) . '</h2>';

    $form_args = array(
        'echo'           => false,
        'redirect'       => $redirect_to,
        'remember'       => true,
        'label_username' => esc_html__( 'Email or Username', 'bsync-member' ),
        'label_password' => esc_html__( 'Password', 'bsync-member' ),
        'label_remember' => esc_html__( 'Remember Me', 'bsync-member' ),
        'label_log_in'   => esc_html__( 'Log In', 'bsync-member' ),
    );

    echo wp_login_form( $form_args );

    $reset_url = wp_lostpassword_url();
    echo '<p class="bsync-member-login-reset">';
    echo '<a href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Forgot your password?', 'bsync-member' ) . '</a>';
    echo '</p>';

    echo '</div>';

    return ob_get_clean();
}
add_shortcode( 'bsync_member_login', 'bsync_member_login_shortcode' );

/**
 * Parse configured member types from settings.
 *
 * Stored in the option bsync_member_member_types_def as one per line,
 * formatted as "slug|Label".
 */
function bsync_member_get_member_types() {
    $raw   = (string) get_option( 'bsync_member_member_types_def', '' );
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $types = array();

    if ( ! is_array( $lines ) ) {
        return $types;
    }

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $parts = explode( '|', $line, 2 );
        $slug  = sanitize_key( trim( $parts[0] ) );
        if ( ! $slug ) {
            continue;
        }

        $label = '';
        if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
            $label = trim( $parts[1] );
        } else {
            $label = ucwords( str_replace( '_', ' ', $slug ) );
        }

        $types[ $slug ] = $label;
    }

    return $types;
}

/**
 * Parse configured manager groups from settings.
 *
 * Stored in bsync_member_manager_groups_def as one per line,
 * formatted as "slug|Label".
 */
function bsync_member_get_manager_groups() {
    $raw   = (string) get_option( 'bsync_member_manager_groups_def', '' );
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $groups = array();

    if ( ! is_array( $lines ) ) {
        return $groups;
    }

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $parts = explode( '|', $line, 2 );
        $slug  = sanitize_key( trim( $parts[0] ) );
        if ( ! $slug ) {
            continue;
        }

        $label = '';
        if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
            $label = trim( $parts[1] );
        } else {
            $label = ucwords( str_replace( '_', ' ', $slug ) );
        }

        $groups[ $slug ] = $label;
    }

    return $groups;
}

/**
 * Ensure each configured manager group also exists as a real WordPress role.
 *
 * For every manager group slug/label defined in settings, we create (or update)
 * a WP role with the same slug and label, and copy the capabilities from the
 * core Member Manager role so they behave the same on the Users screen.
 */
function bsync_member_sync_manager_group_roles() {
    $manager_groups = bsync_member_get_manager_groups();
    if ( empty( $manager_groups ) ) {
        return;
    }

    // Use the base Member Manager role as the template for capabilities.
    $base_role = get_role( BSYNC_MEMBER_MANAGER_ROLE );
    if ( ! $base_role ) {
        return;
    }

    $base_caps = is_array( $base_role->capabilities ) ? $base_role->capabilities : array();

    foreach ( $manager_groups as $slug => $label ) {
        $slug  = sanitize_key( $slug );
        $label = (string) $label;
        if ( ! $slug ) {
            continue;
        }

        $role = get_role( $slug );
        if ( ! $role ) {
            // Create a new role that mirrors the Member Manager capabilities.
            add_role( $slug, $label ? $label : ucwords( str_replace( '_', ' ', $slug ) ), $base_caps );
        } else {
            // Keep the role's display name in sync with the configured label.
            global $wp_roles;
            if ( isset( $wp_roles->roles[ $slug ] ) ) {
                $wp_roles->roles[ $slug ]['name'] = $label ? $label : ucwords( str_replace( '_', ' ', $slug ) );
                $wp_roles->role_names[ $slug ]    = $label ? $label : ucwords( str_replace( '_', ' ', $slug ) );
            }

            // Ensure it has at least all the base manager capabilities.
            foreach ( $base_caps as $cap_name => $granted ) {
                if ( $granted ) {
                    $role->add_cap( $cap_name );
                }
            }
        }
    }
}
add_action( 'init', 'bsync_member_sync_manager_group_roles', 30 );

/**
 * Parse manager → member type mappings from settings.
 *
 * Stored in bsync_member_role_mapping_def as one per line in the form:
 *   manager_slug: member_slug1, member_slug2
 */
function bsync_member_get_manager_member_map() {
    $raw   = (string) get_option( 'bsync_member_role_mapping_def', '' );
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $map   = array();

    if ( ! is_array( $lines ) ) {
        return $map;
    }

    $member_types   = bsync_member_get_member_types();
    $manager_groups = bsync_member_get_manager_groups();

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $parts = explode( ':', $line, 2 );
        if ( count( $parts ) < 2 ) {
            continue;
        }

        $manager_slug = sanitize_key( trim( $parts[0] ) );
        if ( ! $manager_slug || ! isset( $manager_groups[ $manager_slug ] ) ) {
            continue;
        }

        $members_part = trim( $parts[1] );
        if ( '' === $members_part ) {
            continue;
        }

        $member_slugs = preg_split( '/,/', $members_part );
        $clean        = array();
        foreach ( $member_slugs as $m_slug ) {
            $m_slug = sanitize_key( trim( $m_slug ) );
            if ( $m_slug && isset( $member_types[ $m_slug ] ) ) {
                $clean[ $m_slug ] = $m_slug;
            }
        }

        if ( ! empty( $clean ) ) {
            $map[ $manager_slug ] = array_values( $clean );
        }
    }

    return $map;
}

/**
 * Determine which member types the current user is allowed to see in the
 * Members admin screen.
 *
 * - Administrators can optionally filter by a specific member type via
 *   the bsync_member_member_type query parameter.
 * - Member Managers are restricted to those member types mapped to their
 *   configured manager group.
 *
 * Returns an array of member type slugs that should be enforced in the
 * members query. An empty array means "no restriction".
 */
function bsync_member_get_allowed_member_types_for_current_user() {
    $all_types = bsync_member_get_member_types();

    // Site admins: optionally filter by a chosen member type.
    if ( current_user_can( 'manage_options' ) ) {
        if ( empty( $_GET['bsync_member_member_type'] ) ) {
            return array();
        }

        $slug = sanitize_key( wp_unslash( $_GET['bsync_member_member_type'] ) );
        if ( $slug && isset( $all_types[ $slug ] ) ) {
            return array( $slug );
        }

        return array();
    }

    // Non-admin member managers: limit to mapping.
    if ( current_user_can( BSYNC_MEMBER_MANAGE_CAP ) ) {
        $group = get_user_meta( get_current_user_id(), 'bsync_member_manager_group', true );
        $group = sanitize_key( $group );
        if ( ! $group ) {
            return array();
        }

        $map = bsync_member_get_manager_member_map();
        if ( isset( $map[ $group ] ) && ! empty( $map[ $group ] ) ) {
            return $map[ $group ];
        }
    }

    return array();
}

/**
 * Add Bsync Member fields to the user profile screen so admins can assign
 * member types and manager groups to specific users.
 */
function bsync_member_show_user_fields( $user ) {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( BSYNC_MEMBER_MANAGE_CAP ) ) {
        return;
    }

    $member_types   = bsync_member_get_member_types();
    $manager_groups = bsync_member_get_manager_groups();

    if ( empty( $member_types ) && empty( $manager_groups ) ) {
        return;
    }

    $user_member_type   = sanitize_key( get_user_meta( $user->ID, 'bsync_member_type', true ) );
    $user_manager_group = sanitize_key( get_user_meta( $user->ID, 'bsync_member_manager_group', true ) );

    echo '<h2>' . esc_html__( 'Bsync Member Settings', 'bsync-member' ) . '</h2>';
    echo '<table class="form-table" role="presentation">';

    if ( ! empty( $member_types ) ) {
        echo '<tr><th><label for="bsync_member_type">' . esc_html__( 'Member type', 'bsync-member' ) . '</label></th><td>';
        echo '<select name="bsync_member_type" id="bsync_member_type">';
        echo '<option value="">' . esc_html__( '— None / not set —', 'bsync-member' ) . '</option>';
        foreach ( $member_types as $slug => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $slug ),
                selected( $user_member_type, $slug, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Controls which restricted pages and which manager groups this member is associated with.', 'bsync-member' ) . '</p>';
        echo '</td></tr>';
    }

    if ( ! empty( $manager_groups ) && user_can( $user, BSYNC_MEMBER_MANAGE_CAP ) ) {
        echo '<tr><th><label for="bsync_member_manager_group">' . esc_html__( 'Member manager group', 'bsync-member' ) . '</label></th><td>';
        echo '<select name="bsync_member_manager_group" id="bsync_member_manager_group">';
        echo '<option value="">' . esc_html__( '— None / global —', 'bsync-member' ) . '</option>';
        foreach ( $manager_groups as $slug => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $slug ),
                selected( $user_manager_group, $slug, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Determines which member types this manager can see, based on the mapping defined in settings.', 'bsync-member' ) . '</p>';
        echo '</td></tr>';
    }

    echo '</table>';
}
add_action( 'show_user_profile', 'bsync_member_show_user_fields' );
add_action( 'edit_user_profile', 'bsync_member_show_user_fields' );

/**
 * Save Bsync Member user profile fields.
 */
function bsync_member_save_user_fields( $user_id ) {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( BSYNC_MEMBER_MANAGE_CAP ) ) {
        return;
    }

    if ( isset( $_POST['bsync_member_type'] ) ) {
        $type = sanitize_key( wp_unslash( $_POST['bsync_member_type'] ) );
        if ( $type ) {
            update_user_meta( $user_id, 'bsync_member_type', $type );
        } else {
            delete_user_meta( $user_id, 'bsync_member_type' );
        }
    }

    if ( isset( $_POST['bsync_member_manager_group'] ) ) {
        $group = sanitize_key( wp_unslash( $_POST['bsync_member_manager_group'] ) );
        if ( $group ) {
            update_user_meta( $user_id, 'bsync_member_manager_group', $group );
            // Ensure any user assigned to a manager group also has the core Member Manager role
            // so they receive all necessary capabilities.
            $user = get_user_by( 'id', $user_id );
            if ( $user && ! in_array( BSYNC_MEMBER_MANAGER_ROLE, (array) $user->roles, true ) ) {
                $user->add_role( BSYNC_MEMBER_MANAGER_ROLE );
            }
        } else {
            delete_user_meta( $user_id, 'bsync_member_manager_group' );
        }
    }
}
add_action( 'personal_options_update', 'bsync_member_save_user_fields' );
add_action( 'edit_user_profile_update', 'bsync_member_save_user_fields' );

/**
 * Add a visibility meta box to regular WordPress pages and member pages so
 * admins can restrict access to specific member types or to members in
 * general.
 */
function bsync_member_add_page_visibility_metabox() {

    // Regular WordPress pages.
    add_meta_box(
        'bsync_member_page_visibility',
        __( 'Bsync Member Visibility', 'bsync-member' ),
        'bsync_member_render_page_visibility_metabox',
        'page',
        'side',
        'default'
    );

    // Member pages (CPT) – use the same visibility controls.
    add_meta_box(
        'bsync_member_page_visibility',
        __( 'Bsync Member Visibility', 'bsync-member' ),
        'bsync_member_render_page_visibility_metabox',
        BSYNC_MEMBER_PAGE_CPT,
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'bsync_member_add_page_visibility_metabox' );

/**
 * Render the page visibility meta box.
 */
function bsync_member_render_page_visibility_metabox( $post ) {
    wp_nonce_field( 'bsync_member_save_page_visibility', 'bsync_member_page_visibility_nonce' );

    $visibility = get_post_meta( $post->ID, 'bsync_member_page_visibility', true );
    if ( ! $visibility ) {
        $visibility = 'public';
    }

    $current_page_type = get_post_meta( $post->ID, 'bsync_member_page_type', true );

    $allowed_types = get_post_meta( $post->ID, 'bsync_member_page_member_types', true );
    if ( ! is_array( $allowed_types ) ) {
        $allowed_types = array();
    }

    $member_types = bsync_member_get_member_types();
    $page_types   = bsync_member_get_page_types();
    $page_map     = bsync_member_get_page_type_map();

    echo '<p>' . esc_html__( 'Control who can see this page on the front end.', 'bsync-member' ) . '</p>';

    // Optional page type selector, used to suggest default member roles.
    if ( ! empty( $page_types ) ) {
        echo '<p><label for="bsync_member_page_type"><strong>' . esc_html__( 'Page type (optional)', 'bsync-member' ) . '</strong></label><br />';
        echo '<select name="bsync_member_page_type" id="bsync_member_page_type" style="width:100%;max-width:100%;">';
        echo '<option value="">' . esc_html__( '— No specific page type —', 'bsync-member' ) . '</option>';
        foreach ( $page_types as $slug => $label ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $slug ),
                selected( $current_page_type, $slug, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<br /><span class="description">' . esc_html__( 'If this page type is mapped to specific member roles, those roles will be pre-selected below when using “Specific member types only”.', 'bsync-member' ) . '</span>';
        echo '</p>';
    }

    echo '<p><label><input type="radio" name="bsync_member_page_visibility" value="public" ' . checked( 'public', $visibility, false ) . ' /> ' . esc_html__( 'Public (any visitor)', 'bsync-member' ) . '</label></p>';
    echo '<p><label><input type="radio" name="bsync_member_page_visibility" value="members" ' . checked( 'members', $visibility, false ) . ' /> ' . esc_html__( 'Members only (any member with portal access)', 'bsync-member' ) . '</label></p>';

    echo '<p><label><input type="radio" name="bsync_member_page_visibility" value="member_types" ' . checked( 'member_types', $visibility, false ) . ' /> ' . esc_html__( 'Specific member types only', 'bsync-member' ) . '</label></p>';

    if ( ! empty( $member_types ) ) {
        // If no explicit allowed types are saved yet but a page type is
        // chosen and has a mapping, use that as the suggested defaults.
        if ( empty( $allowed_types ) && $current_page_type && isset( $page_map[ $current_page_type ] ) ) {
            $allowed_types = (array) $page_map[ $current_page_type ];
        }

        echo '<div style="margin-left:1.5em;">';
        foreach ( $member_types as $slug => $label ) {
            $checked = in_array( $slug, $allowed_types, true );
            printf(
                '<p><label><input type="checkbox" name="bsync_member_page_member_types[]" value="%s" %s /> %s</label></p>',
                esc_attr( $slug ),
                checked( $checked, true, false ),
                esc_html( $label )
            );
        }
        echo '<p class="description">' . esc_html__( 'If no types are selected, any member can see the page.', 'bsync-member' ) . '</p>';
        echo '</div>';
    } else {
        echo '<p class="description">' . esc_html__( 'No member types are configured yet. Configure them under Bsync Members → Settings.', 'bsync-member' ) . '</p>';
    }
}

/**
 * Save page visibility meta when a page is saved.
 */
function bsync_member_save_page_visibility( $post_id ) {
    if ( ! isset( $_POST['bsync_member_page_visibility_nonce'] ) || ! wp_verify_nonce( $_POST['bsync_member_page_visibility_nonce'], 'bsync_member_save_page_visibility' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $visibility = isset( $_POST['bsync_member_page_visibility'] ) ? sanitize_key( wp_unslash( $_POST['bsync_member_page_visibility'] ) ) : 'public';
    if ( ! in_array( $visibility, array( 'public', 'members', 'member_types' ), true ) ) {
        $visibility = 'public';
    }

    update_post_meta( $post_id, 'bsync_member_page_visibility', $visibility );

    // Save selected page type (used for suggesting default roles).
    if ( isset( $_POST['bsync_member_page_type'] ) ) {
        $page_type = sanitize_key( wp_unslash( $_POST['bsync_member_page_type'] ) );
        if ( $page_type ) {
            update_post_meta( $post_id, 'bsync_member_page_type', $page_type );
        } else {
            delete_post_meta( $post_id, 'bsync_member_page_type' );
        }
    }

    if ( 'member_types' === $visibility && isset( $_POST['bsync_member_page_member_types'] ) && is_array( $_POST['bsync_member_page_member_types'] ) ) {
        $types = array();
        foreach ( $_POST['bsync_member_page_member_types'] as $slug ) {
            $slug = sanitize_key( wp_unslash( $slug ) );
            if ( $slug ) {
                $types[ $slug ] = $slug;
            }
        }
        update_post_meta( $post_id, 'bsync_member_page_member_types', array_values( $types ) );
    } else {
        delete_post_meta( $post_id, 'bsync_member_page_member_types' );
    }
}
add_action( 'save_post_page', 'bsync_member_save_page_visibility' );
add_action( 'save_post_' . BSYNC_MEMBER_PAGE_CPT, 'bsync_member_save_page_visibility' );

/**
 * Admin page: Member Roles – define member manager groups, member types,
 * and which member types each manager group can work with.
 */
function bsync_member_render_roles_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'bsync-member' ) );
    }

    $notice = '';

    // Load existing definitions using the helper parsers.
    $member_types   = bsync_member_get_member_types();
    $manager_groups = bsync_member_get_manager_groups();
    $mapping        = bsync_member_get_manager_member_map();

    if ( ! empty( $_POST['bsync_member_roles_nonce'] ) && wp_verify_nonce( $_POST['bsync_member_roles_nonce'], 'bsync_member_save_roles' ) ) {
        // Update labels for existing manager groups.
        if ( isset( $_POST['bsync_member_manager_labels'] ) && is_array( $_POST['bsync_member_manager_labels'] ) ) {
            $new_labels = array();
            foreach ( $manager_groups as $slug => $old_label ) {
                if ( isset( $_POST['bsync_member_manager_labels'][ $slug ] ) ) {
                    $label = sanitize_text_field( wp_unslash( $_POST['bsync_member_manager_labels'][ $slug ] ) );
                    if ( '' === $label ) {
                        $label = $old_label;
                    }
                    $new_labels[ $slug ] = $label;
                } else {
                    $new_labels[ $slug ] = $old_label;
                }
            }
            $manager_groups = $new_labels;

            $lines = array();
            foreach ( $manager_groups as $s => $l ) {
                $lines[] = $s . '|' . $l;
            }
            update_option( 'bsync_member_manager_groups_def', implode( "\n", $lines ) );
            $notice = __( 'Member manager role labels updated.', 'bsync-member' );
        }

        // Update labels for existing member types.
        if ( isset( $_POST['bsync_member_member_labels'] ) && is_array( $_POST['bsync_member_member_labels'] ) ) {
            $new_labels = array();
            foreach ( $member_types as $slug => $old_label ) {
                if ( isset( $_POST['bsync_member_member_labels'][ $slug ] ) ) {
                    $label = sanitize_text_field( wp_unslash( $_POST['bsync_member_member_labels'][ $slug ] ) );
                    if ( '' === $label ) {
                        $label = $old_label;
                    }
                    $new_labels[ $slug ] = $label;
                } else {
                    $new_labels[ $slug ] = $old_label;
                }
            }
            $member_types = $new_labels;

            $lines = array();
            foreach ( $member_types as $s => $l ) {
                $lines[] = $s . '|' . $l;
            }
            update_option( 'bsync_member_member_types_def', implode( "\n", $lines ) );
            if ( ! $notice ) {
                $notice = __( 'Member role labels updated.', 'bsync-member' );
            }
        }

        // Add a new member manager group.
        if ( ! empty( $_POST['bsync_member_new_manager_group'] ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['bsync_member_new_manager_group'] ) );
            if ( $label ) {
                $slug = sanitize_key( $label );
                if ( ! isset( $manager_groups[ $slug ] ) ) {
                    $manager_groups[ $slug ] = $label;

                    // Persist back to the definitions option as lines of slug|Label.
                    $lines = array();
                    foreach ( $manager_groups as $s => $l ) {
                        $lines[] = $s . '|' . $l;
                    }
                    update_option( 'bsync_member_manager_groups_def', implode( "\n", $lines ) );
                    $notice = __( 'Member manager group added.', 'bsync-member' );
                } else {
                    $notice = __( 'That manager group already exists.', 'bsync-member' );
                }
            }
        }

        // Add a new member type.
        if ( ! empty( $_POST['bsync_member_new_member_type'] ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['bsync_member_new_member_type'] ) );
            if ( $label ) {
                $slug = sanitize_key( $label );
                if ( ! isset( $member_types[ $slug ] ) ) {
                    $member_types[ $slug ] = $label;

                    $lines = array();
                    foreach ( $member_types as $s => $l ) {
                        $lines[] = $s . '|' . $l;
                    }
                    update_option( 'bsync_member_member_types_def', implode( "\n", $lines ) );
                    $notice = __( 'Member type added.', 'bsync-member' );
                } else {
                    $notice = __( 'That member type already exists.', 'bsync-member' );
                }
            }
        }

        // Save manager → member type mapping via checkboxes.
        if ( isset( $_POST['bsync_member_manager_map'] ) && is_array( $_POST['bsync_member_manager_map'] ) ) {
            $submitted = $_POST['bsync_member_manager_map'];

            $map_lines = array();
            $new_map   = array();

            foreach ( $manager_groups as $manager_slug => $manager_label ) {
                $allowed = array();
                if ( isset( $submitted[ $manager_slug ] ) && is_array( $submitted[ $manager_slug ] ) ) {
                    foreach ( $submitted[ $manager_slug ] as $member_slug ) {
                        $member_slug = sanitize_key( wp_unslash( $member_slug ) );
                        if ( isset( $member_types[ $member_slug ] ) ) {
                            $allowed[ $member_slug ] = $member_slug;
                        }
                    }
                }

                if ( ! empty( $allowed ) ) {
                    $new_map[ $manager_slug ] = array_values( $allowed );
                    $map_lines[]              = $manager_slug . ': ' . implode( ', ', $new_map[ $manager_slug ] );
                }
            }

            update_option( 'bsync_member_role_mapping_def', implode( "\n", $map_lines ) );
            $mapping = $new_map;

            if ( ! $notice ) {
                $notice = __( 'Role mappings saved.', 'bsync-member' );
            }
        }

        // Refresh parsed values after any changes.
        $member_types   = bsync_member_get_member_types();
        $manager_groups = bsync_member_get_manager_groups();
        $mapping        = bsync_member_get_manager_member_map();

        // Ensure WordPress roles are kept in sync with manager groups.
        bsync_member_sync_manager_group_roles();
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Member Roles & Manager Groups', 'bsync-member' ) . '</h1>';

    if ( $notice ) {
        echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field( 'bsync_member_save_roles', 'bsync_member_roles_nonce' );

    echo '<h2>' . esc_html__( 'Create Member Manager Roles', 'bsync-member' ) . '</h2>';
    echo '<p>' . esc_html__( 'Add labels for different groups of member managers (for example “Camp 1 Managers”, “Camp 2 Managers”).', 'bsync-member' ) . '</p>';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="bsync_member_new_manager_group">' . esc_html__( 'New member manager role', 'bsync-member' ) . '</label></th><td>';
    echo '<input type="text" class="regular-text" name="bsync_member_new_manager_group" id="bsync_member_new_manager_group" /> ';
    submit_button( __( 'Add Manager Role', 'bsync-member' ), 'secondary', 'submit_add_manager_group', false );
    echo '<p class="description">' . esc_html__( 'The text you enter here becomes a label; a machine-readable slug is generated automatically.', 'bsync-member' ) . '</p>';
    echo '</td></tr>';
    echo '</table>';

    if ( ! empty( $manager_groups ) ) {
        echo '<h3>' . esc_html__( 'Existing member manager roles', 'bsync-member' ) . '</h3>';
        echo '<table class="widefat striped" style="max-width:600px;">';
        echo '<thead><tr><th>' . esc_html__( 'Slug', 'bsync-member' ) . '</th><th>' . esc_html__( 'Label', 'bsync-member' ) . '</th></tr></thead><tbody>';
        foreach ( $manager_groups as $slug => $label ) {
            echo '<tr>';
            echo '<td><code>' . esc_html( $slug ) . '</code></td>';
            echo '<td><input type="text" class="regular-text" name="bsync_member_manager_labels[' . esc_attr( $slug ) . ']" value="' . esc_attr( $label ) . '" /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'You can rename these labels at any time. Slugs stay the same.', 'bsync-member' ) . '</p>';
    }

    echo '<hr />';

    echo '<h2>' . esc_html__( 'Create Member Roles', 'bsync-member' ) . '</h2>';
    echo '<p>' . esc_html__( 'Add different member roles or types (for example “Campers”, “Leaders”).', 'bsync-member' ) . '</p>';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="bsync_member_new_member_type">' . esc_html__( 'New member role', 'bsync-member' ) . '</label></th><td>';
    echo '<input type="text" class="regular-text" name="bsync_member_new_member_type" id="bsync_member_new_member_type" /> ';
    submit_button( __( 'Add Member Role', 'bsync-member' ), 'secondary', 'submit_add_member_type', false );
    echo '<p class="description">' . esc_html__( 'Again, the label is for humans; a slug is generated automatically.', 'bsync-member' ) . '</p>';
    echo '</td></tr>';
    echo '</table>';

    if ( ! empty( $member_types ) ) {
        echo '<h3>' . esc_html__( 'Existing member roles', 'bsync-member' ) . '</h3>';
        echo '<table class="widefat striped" style="max-width:600px;">';
        echo '<thead><tr><th>' . esc_html__( 'Slug', 'bsync-member' ) . '</th><th>' . esc_html__( 'Label', 'bsync-member' ) . '</th></tr></thead><tbody>';
        foreach ( $member_types as $slug => $label ) {
            echo '<tr>';
            echo '<td><code>' . esc_html( $slug ) . '</code></td>';
            echo '<td><input type="text" class="regular-text" name="bsync_member_member_labels[' . esc_attr( $slug ) . ']" value="' . esc_attr( $label ) . '" /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Change labels here to update how member roles appear throughout the admin.', 'bsync-member' ) . '</p>';
    }

    if ( ! empty( $manager_groups ) && ! empty( $member_types ) ) {
        echo '<hr />';
        echo '<h2>' . esc_html__( 'Assign Member Roles to Member Manager Roles', 'bsync-member' ) . '</h2>';
        echo '<p>' . esc_html__( 'For each member manager role, choose which member roles they are responsible for. This controls which members they see on the Members screen.', 'bsync-member' ) . '</p>';

        echo '<table class="widefat striped" style="max-width:800px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Member Manager Role', 'bsync-member' ) . '</th>';
        echo '<th>' . esc_html__( 'Allowed Member Roles', 'bsync-member' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $manager_groups as $manager_slug => $manager_label ) {
            echo '<tr>';
            echo '<td><strong>' . esc_html( $manager_label ) . '</strong><br /><code>' . esc_html( $manager_slug ) . '</code></td>';
            echo '<td>';

            $current_allowed = isset( $mapping[ $manager_slug ] ) ? (array) $mapping[ $manager_slug ] : array();

            foreach ( $member_types as $member_slug => $member_label ) {
                $checked = in_array( $member_slug, $current_allowed, true );
                printf(
                    '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="bsync_member_manager_map[%1$s][]" value="%2$s" %3$s /> %4$s</label>',
                    esc_attr( $manager_slug ),
                    esc_attr( $member_slug ),
                    checked( $checked, true, false ),
                    esc_html( $member_label )
                );
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:10px;">';
        submit_button( __( 'Save Role Assignments', 'bsync-member' ), 'primary', 'submit_save_mapping', false );
        echo '</p>';
    }

    echo '</form>';
    echo '</div>';
}

/**
 * Admin page: Page Types – define logical page types and map them to
 * member roles (types). This does not change WordPress post types; it
 * just creates named groups you can use when planning which roles see
 * which kinds of content.
 */
function bsync_member_render_page_types_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'bsync-member' ) );
    }

    $notice = '';

    $page_types   = bsync_member_get_page_types();
    $member_types = bsync_member_get_member_types();
    $page_map     = bsync_member_get_page_type_map();

    if ( ! empty( $_POST['bsync_member_page_types_nonce'] ) && wp_verify_nonce( $_POST['bsync_member_page_types_nonce'], 'bsync_member_save_page_types' ) ) {
        // First, update labels for existing page types.
        if ( isset( $_POST['bsync_member_page_type_labels'] ) && is_array( $_POST['bsync_member_page_type_labels'] ) ) {
            $submitted_labels = wp_unslash( $_POST['bsync_member_page_type_labels'] );
            $new_types        = array();
            $lines            = array();

            foreach ( $page_types as $slug => $old_label ) {
                $new_label = isset( $submitted_labels[ $slug ] ) ? sanitize_text_field( $submitted_labels[ $slug ] ) : $old_label;
                if ( ! $new_label ) {
                    $new_label = $old_label;
                }
                $new_types[ $slug ] = $new_label;
                $lines[]             = $slug . '|' . $new_label;
            }

            if ( ! empty( $lines ) ) {
                update_option( 'bsync_member_page_types_def', implode( "\n", $lines ) );
                $page_types = $new_types;

                if ( ! $notice ) {
                    $notice = __( 'Page type labels updated.', 'bsync-member' );
                }
            }
        }

        // Add a new page type.
        if ( ! empty( $_POST['bsync_member_new_page_type'] ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['bsync_member_new_page_type'] ) );
            if ( $label ) {
                $slug = sanitize_key( $label );
                if ( ! isset( $page_types[ $slug ] ) ) {
                    $page_types[ $slug ] = $label;
                    $lines               = array();
                    foreach ( $page_types as $s => $l ) {
                        $lines[] = $s . '|' . $l;
                    }
                    update_option( 'bsync_member_page_types_def', implode( "\n", $lines ) );
                    $notice = __( 'Page type added.', 'bsync-member' );
                } else {
                    $notice = __( 'That page type already exists.', 'bsync-member' );
                }
            }
        }

        // Save mapping of page types → member types.
        if ( isset( $_POST['bsync_member_page_type_map'] ) && is_array( $_POST['bsync_member_page_type_map'] ) ) {
            $submitted = $_POST['bsync_member_page_type_map'];
            $map_lines = array();
            $new_map   = array();

            foreach ( $page_types as $page_slug => $page_label ) {
                $allowed = array();
                if ( isset( $submitted[ $page_slug ] ) && is_array( $submitted[ $page_slug ] ) ) {
                    foreach ( $submitted[ $page_slug ] as $member_slug ) {
                        $member_slug = sanitize_key( wp_unslash( $member_slug ) );
                        if ( isset( $member_types[ $member_slug ] ) ) {
                            $allowed[ $member_slug ] = $member_slug;
                        }
                    }
                }

                if ( ! empty( $allowed ) ) {
                    $new_map[ $page_slug ] = array_values( $allowed );
                    $map_lines[]           = $page_slug . ': ' . implode( ', ', $new_map[ $page_slug ] );
                }
            }

            update_option( 'bsync_member_page_types_mapping_def', implode( "\n", $map_lines ) );
            $page_map = $new_map;

            if ( ! $notice ) {
                $notice = __( 'Page type role assignments saved.', 'bsync-member' );
            }
        }

        // Refresh values after saves.
        $page_types   = bsync_member_get_page_types();
        $member_types = bsync_member_get_member_types();
        $page_map     = bsync_member_get_page_type_map();
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Page Types', 'bsync-member' ) . '</h1>';

    if ( $notice ) {
        echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field( 'bsync_member_save_page_types', 'bsync_member_page_types_nonce' );

    echo '<h2>' . esc_html__( 'Create Page Types', 'bsync-member' ) . '</h2>';
    echo '<p>' . esc_html__( 'These are logical groups of pages (for example “Camp Info Pages”, “Leader Resources”). They do not create new WordPress post types, but you can use them to plan which roles should see which kinds of content.', 'bsync-member' ) . '</p>';

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="bsync_member_new_page_type">' . esc_html__( 'New page type', 'bsync-member' ) . '</label></th><td>';
    echo '<input type="text" class="regular-text" name="bsync_member_new_page_type" id="bsync_member_new_page_type" /> ';
    submit_button( __( 'Add Page Type', 'bsync-member' ), 'secondary', 'submit_add_page_type', false );
    echo '<p class="description">' . esc_html__( 'Enter a label; a machine-readable slug will be generated automatically.', 'bsync-member' ) . '</p>';
    echo '</td></tr>';
    echo '</table>';

    if ( ! empty( $page_types ) ) {
        echo '<h3>' . esc_html__( 'Existing Page Types', 'bsync-member' ) . '</h3>';
        echo '<p>' . esc_html__( 'You can rename these labels at any time. Slugs stay the same and are used internally.', 'bsync-member' ) . '</p>';

        echo '<table class="widefat striped" style="max-width:600px;margin-bottom:20px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Slug', 'bsync-member' ) . '</th>';
        echo '<th>' . esc_html__( 'Label', 'bsync-member' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $page_types as $slug => $label ) {
            echo '<tr>';
            echo '<td><code>' . esc_html( $slug ) . '</code></td>';
            echo '<td>';
            printf(
                '<input type="text" class="regular-text" name="bsync_member_page_type_labels[%1$s]" value="%2$s" />',
                esc_attr( $slug ),
                esc_attr( $label )
            );
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    if ( ! empty( $page_types ) ) {
        echo '<h3>' . esc_html__( 'Assign Page Types to Member Roles', 'bsync-member' ) . '</h3>';
        if ( empty( $member_types ) ) {
            echo '<p>' . esc_html__( 'You have not defined any member roles yet. Visit the Member Roles page first.', 'bsync-member' ) . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:800px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Page Type', 'bsync-member' ) . '</th>';
            echo '<th>' . esc_html__( 'Member Roles Who Should See This Type', 'bsync-member' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $page_types as $page_slug => $page_label ) {
                echo '<tr>';
                echo '<td><strong>' . esc_html( $page_label ) . '</strong><br /><code>' . esc_html( $page_slug ) . '</code></td>';
                echo '<td>';

                $current_allowed = isset( $page_map[ $page_slug ] ) ? (array) $page_map[ $page_slug ] : array();
                foreach ( $member_types as $member_slug => $member_label ) {
                    $checked = in_array( $member_slug, $current_allowed, true );
                    printf(
                        '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="bsync_member_page_type_map[%1$s][]" value="%2$s" %3$s /> %4$s</label>',
                        esc_attr( $page_slug ),
                        esc_attr( $member_slug ),
                        checked( $checked, true, false ),
                        esc_html( $member_label )
                    );
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<p style="margin-top:10px;">';
            submit_button( __( 'Save Page Type Assignments', 'bsync-member' ), 'primary', 'submit_save_page_map', false );
            echo '</p>';
        }
    }

    echo '</form>';
    echo '</div>';
}

/**
 * Helper: parse configured page types (slug|Label per line).
 */
function bsync_member_get_page_types() {
    $raw   = (string) get_option( 'bsync_member_page_types_def', '' );
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $types = array();

    if ( ! is_array( $lines ) ) {
        return $types;
    }

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $parts = explode( '|', $line, 2 );
        $slug  = sanitize_key( trim( $parts[0] ) );
        if ( ! $slug ) {
            continue;
        }

        $label = '';
        if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
            $label = trim( $parts[1] );
        } else {
            $label = ucwords( str_replace( '_', ' ', $slug ) );
        }

        $types[ $slug ] = $label;
    }

    return $types;
}

/**
 * Helper: parse page type → member type mappings (page_slug: member1, member2 lines).
 */
function bsync_member_get_page_type_map() {
    $raw   = (string) get_option( 'bsync_member_page_types_mapping_def', '' );
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $map   = array();

    if ( ! is_array( $lines ) ) {
        return $map;
    }

    $member_types = bsync_member_get_member_types();

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }

        $parts = explode( ':', $line, 2 );
        if ( count( $parts ) < 2 ) {
            continue;
        }

        $page_slug = sanitize_key( trim( $parts[0] ) );
        if ( ! $page_slug ) {
            continue;
        }

        $members_part = trim( $parts[1] );
        if ( '' === $members_part ) {
            continue;
        }

        $member_slugs = preg_split( '/,/', $members_part );
        $clean        = array();
        foreach ( $member_slugs as $m_slug ) {
            $m_slug = sanitize_key( trim( $m_slug ) );
            if ( $m_slug && isset( $member_types[ $m_slug ] ) ) {
                $clean[ $m_slug ] = $m_slug;
            }
        }

        if ( ! empty( $clean ) ) {
            $map[ $page_slug ] = array_values( $clean );
        }
    }

    return $map;
}
