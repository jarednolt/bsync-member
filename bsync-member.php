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
            )
        );
    } else {
        // Ensure it has our capabilities.
        $manager_role->add_cap( 'read' );
        $manager_role->add_cap( BSYNC_MEMBER_MANAGE_CAP );
        $manager_role->add_cap( BSYNC_MEMBER_PORTAL_CAP );
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
        'query_var'         => false,
        'public'            => false,
        'show_in_quick_edit'=> true,
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
        BSYNC_MEMBER_MANAGE_CAP,
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
}
add_action( 'admin_menu', 'bsync_member_register_admin_menu' );

/**
 * Settings screen: rename roles, page type, and category type.
 */
function bsync_member_render_settings_page() {
    if ( ! current_user_can( BSYNC_MEMBER_MANAGE_CAP ) ) {
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

    // Fetch members with the Member role.
    $user_query = new WP_User_Query(
        array(
            'role'   => BSYNC_MEMBER_ROLE,
            'number' => 200,
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
 * access) can view member pages. The pages still have real URLs, so once
 * logged in any member can visit them directly.
 */
function bsync_member_protect_member_pages() {
    if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) ) {
        if ( ! is_user_logged_in() || ! current_user_can( BSYNC_MEMBER_PORTAL_CAP ) ) {
            // Redirect to login and then back to the requested member page.
            $redirect = get_permalink();
            wp_safe_redirect( wp_login_url( $redirect ) );
            exit;
        }
    }
}
add_action( 'template_redirect', 'bsync_member_protect_member_pages' );

/**
 * Ensure member pages are not indexed by search engines even though they are
 * viewable on the front end for logged-in members.
 */
function bsync_member_noindex_member_pages() {
    if ( is_singular( BSYNC_MEMBER_PAGE_CPT ) ) {
        echo "<meta name='robots' content='noindex,nofollow' />\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
    }
}
add_action( 'wp_head', 'bsync_member_noindex_member_pages' );

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
        return '<p>' . esc_html__( 'You must be logged in to access the member portal.', 'bsync-member' ) . '</p>';
    }

    if ( ! current_user_can( BSYNC_MEMBER_PORTAL_CAP ) ) {
        return '<p>' . esc_html__( 'You do not have access to the member portal.', 'bsync-member' ) . '</p>';
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
