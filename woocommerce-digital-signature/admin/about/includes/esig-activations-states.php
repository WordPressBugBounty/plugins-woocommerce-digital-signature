<?php

/**
 * 
 * @package WP E-Signature - Gravity Form
 * @author  Stephen Gravitt <stpehen.gravitt@approveme.com>
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Provide context for the various installation and activation states
 * 
 * @return {string} | One of many strings to represent activation state:
 *    'no_wpe', // wp e-signature not installed
 *    'wpe_inactive', // wp e-signature installed but not active
 *    'wpe_expired', // wp e-signature installed and active but license is expired
 *    'wpe_active_basic', // wp e-signature is installed, active, does not have pro addons
 *    'wpe_active_pro', // wp e-signature is installed, active, AND has pro addons
 */

if(!function_exists('esig_get_activation_state')) {
    
    /**
     * Determine the WP E-Signature core and Business Add-ons activation state.
     *
     * Uses the Business Add-ons bootstrap function so active installs are detected
     * regardless of their plugin directory name or network activation status.
     *
     * @since x.x.x
     *
     * @return string The current E-Signature activation state.
     */
    function esig_get_activation_state(){


        if (!file_exists(WP_PLUGIN_DIR . '/e-signature/e-signature.php') && !function_exists("WP_E_Sig")) {

            return 'no_wpe'; // wp e-signature not installed

        }

        if( ! function_exists("WP_E_Sig") ){

            return 'wpe_inactive'; // wp e-signature installed but not active

        }else{

            if( ! Esign_licenses::is_license_valid() ){

                return 'wpe_expired'; // wp e-signature installed and active but license is expired

            }

            if ( function_exists( 'esig_business_pack_activate' ) ) {
                return 'wpe_active_pro'; // WP E-Signature Business Add-ons is loaded.
            }

            return 'wpe_inactive_pro'; // WP E-Signature Business Add-ons is not loaded.
        }
    }

}

/**
 *  Provides partial template for given names. 
 */
if(!function_exists("esig_load_template")) {
    
  function esig_load_template($path, $arg=array() ){
            $file =  $path . ".php" ; 
            ob_start();
            if(file_exists($file)){
                require_once($file);
                $contents = ob_get_clean();
                return $contents; 
            }

            return "File not found" ; 
    }  
}

/**
 *  return a plugin activation link 
 */
if(!function_exists("esig_plugin_activation_link"))
{
    function esig_plugin_activation_link($plugin_file){
        $activate_url = add_query_arg(
            array(
                '_wpnonce' => wp_create_nonce( 'activate-plugin_' . $plugin_file ),
                'action'   => 'activate',
                'plugin'   => $plugin_file,
            ),
            network_admin_url( 'plugins.php' )
        );

        if ( is_network_admin() ) {
            $activate_url = add_query_arg( array( 'networkwide' => 1 ), $activate_url );
        }
        return $activate_url;
    }
}
