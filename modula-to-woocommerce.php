<?php
/*
Plugin Name: Modula to Woocommerce
Description: This plugin migrates a custom "Modula" plugin based product system, to a standardized Woocommerce product system. This plugin splices taxonomies together to cleanly associate the category from the gallery with the rest of the product data embedded in the metadata for the principal image media library item. Highly recommend increasing max_execution_time in .htaccess and set_time_limit in wp-config.php to at least 10800, depending on how many products need to be migrated and the speed of your hosting.
Author: Liam Sherman Parris
Version: 1.0
*/

/*
The way this particular Modula implementation worked was: a regular image upload was used as a product, and these product images were grouped in galleries. This represented a split taxonomy that needed to be combined while creating the equivalent Woocommerce product.
*/

if (!defined("ABSPATH")) {
    // Exit if accessed directly
    exit();
}

// Check if WooCommerce is active
if (
    in_array(
        "woocommerce/woocommerce.php",
        apply_filters("active_plugins", get_option("active_plugins"))
    )
) {
    // Add new admin page under the Tools menu
    function itp_add_admin_page()
    {
        add_management_page(
            "Image to Product",
            "Image to Product",
            "manage_options",
            "image-to-product",
            "itp_admin_page"
        );
    }
    add_action("admin_menu", "itp_add_admin_page");

    // Create the callback for the admin page
    function itp_admin_page()
    {
        echo '<div class="itp_container">';
        echo '<style>
        .itp_container {
            font-family: Arial, sans-serif;
            margin: 0 auto;
            max-width: 800px;
            background-color: #e8f8f5;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px 0 hsla(0, 0%, 0%, 0.2);
        }
        .itp_container h2 {
            font-size: 36px;
            color: #0d4d4d;
            text-align: center;
            margin-bottom: 20px;
        }
        .itp_container p {
            color: #0d4d4d;
            font-size: 16px;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .itp_container form {
            text-align: center;
        }
        .itp_container input[type="submit"] {
            background-color: #BB569F;
            color: white;
            font-weight: 900;
            text-transform: uppercase;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .itp_container input[type="submit"]:hover {
            background-color:  #FFCCF0
        }
    </style>';
        echo "<h2>Image to Product Migration</h2>";
        echo "<p>This tool converts your existing Modula gallery-based product system to a standardized WooCommerce product system. By splicing taxonomies, it seamlessly associates your gallery categories with the rest of your product data, turning images into full-fledged WooCommerce products.</p>";
        echo "<p>Please note that depending on the number of products to be migrated and your server's provisioned resources, the process may take typically about 2-10 minutes, but could take as long as a few hours. Do not close the tab during this process. To ensure the migration isn't interrupted by a timeout, I  recommend setting max_execution_time in .htaccess and set_time_limit in wp-config.php to at least 10800.</p>";
        echo '<form method="post"><input type="submit" name="itp_run" value="Start Conversion"></form>';
        echo "</div>";
        if (isset($_POST["itp_run"])) {
            itp_convert_images_to_products();
        }
    }

    // Function to get image IDs from gallery
    function get_image_ids_from_gallery($gallery_id)
    {
        global $wpdb;
        $image_ids = [];
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id = %d
                AND meta_key = 'modula-images'",
                $gallery_id
            )
        );
        if (!empty($results)) {
            $meta_value = unserialize($results[0]->meta_value);
            if (is_array($meta_value)) {
                foreach ($meta_value as $image) {
                    if (isset($image["id"])) {
                        $image_ids[] = $image["id"];
                    }
                }
            }
        }
        return $image_ids;
    }

    // Main function to convert images to products
    function itp_convert_images_to_products()
    {
        $args = [
            "post_type" => "modula-gallery",
            "post_status" => "publish",
            "posts_per_page" => -1,
        ];
        $query_galleries = new WP_Query($args);
        foreach ($query_galleries->posts as $gallery) {
            $image_ids = get_image_ids_from_gallery($gallery->ID);
            foreach ($image_ids as $image_id) {
                $image = get_post($image_id);
                if ($image && $image->post_type == "attachment") {
                    // Verify that the image title is numeric - Remove this if your product system doesn't have an ID in the title.
                    if (!ctype_digit($image->post_title)) {
                        // Skip this image if its title is not numeric
                        continue;
                    }
                    // Verify that a product with the same title doesn't already exist
                    if (
                        get_page_by_title($image->post_title, OBJECT, "product")
                    ) {
                        // Skip this image if a product with the same title exists
                        continue;
                    }
                    $product = new WC_Product();
                    $product->set_name($image->post_title);
                    $product->set_status("publish");
                    $product->set_catalog_visibility("visible");
                    $product->set_description(
                        $image->post_content
                            ? $image->post_content
                            : $image->post_excerpt
                    );
                    $product->set_image_id($image->ID);

                    // Set the product's category to the title of the gallery
                    $term = get_term_by(
                        "name",
                        $gallery->post_title,
                        "product_cat"
                    );
                    if ($term === false) {
                        $term_data = wp_insert_term(
                            $gallery->post_title,
                            "product_cat"
                        );
                        if (!is_wp_error($term_data)) {
                            $product->set_category_ids([$term_data["term_id"]]);
                        }
                    } else {
                        $product->set_category_ids([$term->term_id]);
                    }

                    $product->save();
                }
            }
        }
    }
}
?>
