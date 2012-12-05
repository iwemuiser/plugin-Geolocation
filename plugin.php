<?php
define('GOOGLE_MAPS_API_VERSION', '3.x');
define('GEOLOCATION_MAX_LOCATIONS_PER_PAGE', 500);
define('GEOLOCATION_DEFAULT_LOCATIONS_PER_PAGE', 40);

require_once 'Location.php';

// Plugin Hooks
add_plugin_hook('install', 'geolocation_install');
add_plugin_hook('uninstall', 'geolocation_uninstall');
add_plugin_hook('config_form', 'geolocation_config_form');
add_plugin_hook('config', 'geolocation_config');
add_plugin_hook('define_acl', 'geolocation_define_acl');
add_plugin_hook('define_routes', 'geolocation_add_routes');
add_plugin_hook('after_save_form_item', 'geolocation_save_location');
add_plugin_hook('admin_append_to_items_show_secondary', 'geolocation_admin_show_item_map');
add_plugin_hook('public_append_to_items_show', 'geolocation_public_show_item_map');
add_plugin_hook('admin_append_to_advanced_search', 'geolocation_admin_append_to_advanced_search');
add_plugin_hook('public_append_to_advanced_search', 'geolocation_public_append_to_advanced_search');
add_plugin_hook('item_browse_sql', 'geolocation_item_browse_sql');
add_plugin_hook('contribution_append_to_type_form', 'geolocation_autocomplete_form');
add_plugin_hook('contribution_append_to_type_form', 'geolocation_append_contribution_form');
add_plugin_hook('contribution_save_form', 'geolocation_save_contribution_form');
add_plugin_hook('public_theme_header', 'geolocation_header');

// Plugin Filters
add_filter('admin_navigation_main', 'geolocation_admin_nav');
add_filter('define_response_contexts', 'geolocation_kml_response_context');
add_filter('define_action_contexts', 'geolocation_kml_action_context');
add_filter('admin_items_form_tabs', 'geolocation_item_form_tabs');
add_filter('public_navigation_items', 'geolocation_public_nav');

// Hook Functions
function geolocation_install()
{    
    $db = get_db();
    $sql = "
    CREATE TABLE IF NOT EXISTS $db->Location (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
    `item_id` BIGINT UNSIGNED NOT NULL ,
    `latitude` DOUBLE NOT NULL ,
    `longitude` DOUBLE NOT NULL ,
    `zoom_level` INT NOT NULL ,

    `map_type` VARCHAR( 255 ) NOT NULL ,
	`point_of_interest` VARCHAR( 255 ) NULL ,
	`route` VARCHAR( 255 ) NULL ,
	`street_number` VARCHAR( 255 ) NULL ,
	`sublocality` VARCHAR( 255 ) NULL ,
	`locality` VARCHAR( 255 ) NULL ,
	`administrative_area_level_1` VARCHAR( 255 ) NULL ,
	`administrative_area_level_2` VARCHAR( 255 ) NULL ,
	`administrative_area_level_3` VARCHAR( 255 ) NULL ,
	`natural_feature` VARCHAR( 255 ) NULL ,
	`establishment` VARCHAR( 255 ) NULL ,
	`postal_code` VARCHAR( 255 ) NULL ,
	`postal_code_prefix` VARCHAR( 255 ) NULL ,
	`country` VARCHAR( 255 ) NOT NULL ,
	`continent` VARCHAR( 255 ) NULL ,
	`planetary_body` VARCHAR( 255 ) NULL ,

    `address` TEXT NOT NULL ,
    INDEX (`item_id`)) ENGINE = MYISAM";
    $db->query($sql);
    
    // If necessary, upgrade the plugin options
    geolocation_upgrade_options();
    
    set_option('geolocation_default_latitude', '38');
    set_option('geolocation_default_longitude', '-77');
    set_option('geolocation_default_zoom_level', '5');
    set_option('geolocation_per_page', GEOLOCATION_DEFAULT_LOCATIONS_PER_PAGE);
    set_option('geolocation_add_map_to_contribution_form', '1');
	set_option('geolocation_gmaps_key', none);
}

function geolocation_uninstall()
{
    // Delete the plugin options
    delete_option('geolocation_default_latitude');
	delete_option('geolocation_default_longitude');
	delete_option('geolocation_default_zoom_level');
	delete_option('geolocation_per_page');
    delete_option('geolocation_add_map_to_contribution_form');
    
    // This is for NEWER again! versions of Geolocation, which used to store a Google Map API key.
	delete_option('geolocation_gmaps_key');

    // Drop the Location table
	$db = get_db();
	$db->query("DROP TABLE $db->Location");
}

function geolocation_config_form()
{
    // If necessary, upgrade the plugin options
    geolocation_upgrade_options();

	include 'config_form.php';
}

function geolocation_config()
{   
    // Use the form to set a bunch of default options in the db
    set_option('geolocation_default_latitude', $_POST['default_latitude']);
    set_option('geolocation_default_longitude', $_POST['default_longitude']);
    set_option('geolocation_default_zoom_level', $_POST['default_zoomlevel']); 
    set_option('geolocation_item_map_width', $_POST['item_map_width']); 
    set_option('geolocation_item_map_width', $_POST['item_map_width']); 
    set_option('geolocation_gmaps_key', $_POST['geolocation_gmaps_key']); 
    $perPage = (int)$_POST['per_page'];
    if ($perPage <= 0) {
        $perPage = GEOLOCATION_DEFAULT_LOCATIONS_PER_PAGE;
    } else if ($perPage > GEOLOCATION_MAX_LOCATIONS_PER_PAGE) {
        $perPage = GEOLOCATION_MAX_LOCATIONS_PER_PAGE;
    }
    set_option('geolocation_per_page', $perPage);
    set_option('geolocation_add_map_to_contribution_form', $_POST['geolocation_add_map_to_contribution_form']);
    set_option('geolocation_link_to_nav', $_POST['geolocation_link_to_nav']);
}

function geolocation_upgrade_options() 
{
    // Check for old plugin options, and if necessary, transfer to new options
    $options = array('default_latitude', 'default_longitude', 'default_zoom_level', 'per_page');
    foreach($options as $option) {
        $oldOptionValue = get_option('geo_' . $option);
        if ($oldOptionValue != '') {
            set_option('geolocation_' . $option, $oldOptionValue);
            delete_option('geo_' . $option);        
        }
    }
    delete_option('geo_gmaps_key');
}

function geolocation_define_acl($acl)
{
    $acl->allow(null, 'Items', 'modifyPerPage');
}

function geolocation_public_nav($nav)
{
    if (get_option('geolocation_link_to_nav')) {
        $nav['Browse Map'] = uri('items/map');
    }
    return $nav;
}

/**
 * Plugin hook that can manipulate Omeka's routes to allow for new URIs to 
 * access data
 * Currently does the following things:
 *     matches up the URI items/map/:page with MapController::browseAction()
 * Adds a couple of data feeds to render XML for the map (these pages are in the 
 * xml/ directory)
 * @see Zend_Controller_Router_Rewrite
 * @param $router
 * @return void
 **/
function geolocation_add_routes($router)
{
    $mapRoute = new Zend_Controller_Router_Route('items/map/:page', 
                                                 array('controller' => 'map', 
                                                       'action'     => 'browse', 
                                                       'module'     => 'geolocation',
                                                       'page'       => '1'), 
                                                 array('page' => '\d+'));
    $router->addRoute('items_map', $mapRoute);
    
    // Trying to make the route look like a KML file so google will eat it.
    // @todo Include page parameter if this works.
    $kmlRoute = new Zend_Controller_Router_Route_Regex('geolocation/map\.kml', 
                                                        array('controller' => 'map',
                                                              'action' => 'browse',
                                                              'module' => 'geolocation',
                                                              'output' => 'kml'));
    $router->addRoute('map_kml', $kmlRoute);
}

/**
 * Each time we save an item, check the POST to see if we are also saving a 
 * location
 * @return void
 **/
function geolocation_save_location($item)
{
    $post = $_POST;    

    // If we don't have the geolocation form on the page, don't do anything!
    if (!$post['geolocation']) {
        return;
    }
        
    // Find the location object for the item
    $location = geolocation_get_location_for_item($item, true);
    
    // If we have filled out info for the geolocation, then submit to the db
	// WE WANT TO SAVE THE RETRIEVED DATA
    $geolocationPost = $post['geolocation'];
    if (!empty($geolocationPost) && 
        (((string)$geolocationPost['latitude']) != '') && 
        (((string)$geolocationPost['longitude']) != '')) {
        if (!$location) {
            $location = new Location;
            $location->item_id = $item->id;
        }
        $location->saveForm($geolocationPost);
    // If the form is empty, then we want to delete whatever location is 
    // currently stored
    } else {
        if ($location) {
            $location->delete();
        }
    }
}

// Filter Functions
function geolocation_admin_nav($navArray)
{
    $geoNav = array('Map' => uri('geolocation/map/browse'));
    $navArray += $geoNav;
    return $navArray;
}

function geolocation_kml_response_context($context)
{
    $context['kml'] = array('suffix'  => 'kml', 
                            'headers' => array('Content-Type' => 'text/xml'));
    return $context;
}

function geolocation_kml_action_context($context, $controller)
{
    if ($controller instanceof Geolocation_MapController) {
        $context['browse'] = array('kml');
    }
    return $context;
}

function geolocation_get_map_items_per_page()
{
    $itemsPerMap = (int)get_option('geolocation_per_page') or $itemsPerMap = 10;
    return $itemsPerMap;
}

/**
 * Add a Map tab to the edit item page
 * @return array
 **/
function geolocation_item_form_tabs($tabs)
{
    // insert the map tab before the Miscellaneous tab
    $item = get_current_item();
    $ttabs = array();
    foreach($tabs as $key => $html) {
        if ($key == 'Miscellaneous') {
            $ht = '';
            $ht .= geolocation_scripts();
            $ht .= geolocation_map_form($item);
            $ttabs['Map'] = $ht;
        }
        $ttabs[$key] = $html;
    }
    $tabs = $ttabs;
    return $tabs;
}

// Helpers

/**
 * Returns the html for loading the javascripts used by the plugin.
 *
 * @param bool $pageLoaded Whether or not the page is already loaded.  
 * If this function is used with AJAX, this parameter may need to be set to true.
 * @return string
 */
function geolocation_scripts()
{
    $ht = '';
    $ht .= geolocation_load_google_maps();
#	$ht .= "<script>jQuery.noConflict();</script>";
#    $ht .= js('jquery'); #messes with Omeka's native buttons. FIX IT!
    $ht .= js('map');
    return $ht;
}


/**
 * Returns the html for loading the Google Maps javascript
 *
 * @param bool $pageLoaded Whether or not the page is already loaded.  
 * If this function is used with AJAX, this parameter may need to be set to true.
 * @return string
 */
function geolocation_load_google_maps()
{
    return '<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=false"></script>';
}

/**
 * Returns a location (or array of locations) for an item (or array of items)
 * @param array|Item|int $item An item or item id, or an array of items or item ids
 * @param boolean $findOnlyOne Whether or not to return only one location if it exists for the item
 * @return array|Location A location or an array of locations
 **/
function geolocation_get_location_for_item($item, $findOnlyOne = false)
{
    return get_db()->getTable('Location')->findLocationByItem($item, $findOnlyOne);
}

/**
 * Returns the default center point for the Google Map
 * @return array
 **/
function geolocation_get_center()
{
    return array(
        'latitude'=>  (double) get_option('geolocation_default_latitude'), 
        'longitude'=> (double) get_option('geolocation_default_longitude'), 
        'zoomLevel'=> (double) get_option('geolocation_default_zoom_level'));
}

function geolocation_header($request)
{
    $module = $request->getModuleName();
    $controller = $request->getControllerName();
    $action = $request->getActionName();
    if ( ($module == 'geolocation' && $controller == 'map')
      || ($module == 'contribution' && $controller == 'contribution' && $action == 'contribute' && get_option('geolocation_add_map_to_contribution_form') == '1')):
?>
    <!-- Scripts for the Geolocation items/map page -->
    <?php echo geolocation_scripts(); ?>
    
    <!-- Styles for the Geolocation items/map page -->
    <link rel="stylesheet" href="<?php echo css('geolocation-items-map'); ?>" />
    <link rel="stylesheet" href="<?php echo css('geolocation-marker'); ?>" />
    
<?php
    endif;
}

/**
 * Returns html for a google map
 * @param string $divId The id of the div that holds the google map
 * @param array $options Possible options include:
 *     form = 'geolocation'  (provides the prefix for form elements that should 
 *     catch the map coordinates)
 * @return array
 **/
function geolocation_google_map($divId = 'map', $options = array()) {

    $ht = '';
    $ht .= '<div id="' . $divId . '" class="map"></div>';
    
    // Load this junk in from the plugin config
    $center = geolocation_get_center();
    
    // The request parameters get put into the map options
    $params = array();
    if (!isset($options['params'])) {
        $params = array();
    }
    $params = array_merge($params, $_GET);
    
    if ($options['loadKml']) {
        unset($options['loadKml']);
        // This should not be a link to the public side b/c then all the URLs that
        // are generated inside the KML will also link to the public side.
        $options['uri'] = uri('geolocation/map.kml');
    }
    
    // Merge in extra parameters from the controller
    if (Zend_Registry::isRegistered('map_params')) {
        $params = array_merge($params, Zend_Registry::get('map_params'));
    }
        
    // We are using KML as the output format
    $options['params'] = $params;    
        
    $options = js_escape($options);
    $center = js_escape($center);
    
    ob_start();
?>  
    <script type="text/javascript">
    //<![CDATA[
        var <?php echo Inflector::variablize($divId); ?>OmekaMapBrowse = new OmekaMapBrowse(<?php echo js_escape($divId); ?>, <?php echo $center; ?>, <?php echo $options; ?>);
    //]]>
    </script>
<?php
    $ht .= ob_get_contents();
    ob_end_clean();
    return $ht;
}

/**
 * Returns the google map code for an item
 * @param Item $item
 * @param int $width
 * @param int $height
 * @param boolean $hasBalloonForMarker
 * @return string
 **/
function geolocation_google_map_for_item($item = null, $width = '300px', $height = '200px', $hasBalloonForMarker = true, $markerHtmlClassName = 'geolocation_balloon') {  
    if (!$item) {
        $item = get_current_item();
    }      
    $ht = '';
    $divId = "item-map-{$item->id}";
    ob_start();
    if ($hasBalloonForMarker) {
        echo geolocation_marker_style();        
    }
?>
<style type="text/css" media="screen">
    /* The map for the items page needs a bit of styling on it */
    #address_balloon dt {
        font-weight: bold;
    }
    #address_balloon {
        width: 100px;
    }
</style>
<?php        
    $location = geolocation_get_location_for_item($item, true);
    // Only set the center of the map if this item actually has a location 
    // associated with it
    if ($location) {
        $center['latitude']     = $location->latitude;
        $center['longitude']    = $location->longitude;
        $center['zoomLevel']    = $location->zoom_level;
        $center['show']         = true;
        if ($hasBalloonForMarker) {
            $center['markerHtml']   = geolocation_get_marker_html_for_item($item, $markerHtmlClassName);            
        }
        $center = js_escape($center);
        $options = js_escape($options);
?>
        <div id="<?php echo $divId;?>" style="width:<?php echo $width;?>; height:<?php echo $height;?>;" class="map"></div>
        <script type="text/javascript">
        //<![CDATA[
            var <?php echo Inflector::variablize($divId); ?>OmekaMapSingle = new OmekaMapSingle(<?php echo js_escape($divId); ?>, <?php echo $center; ?>, <?php echo $options; ?>);
        //]]>
        </script>
<?php         
    } else {
?>
        <p class="map-notification">This item has no location info associated with it.</p>
<?php
    }
    $ht .= ob_get_contents();
    ob_end_clean();
    return $ht;
}


function geolocation_get_marker_html_for_item($item, $markerHtmlClassName = 'geolocation_balloon')
{
    $titleLink = link_to_item(item('Dublin Core', 'Title', array(), $item), array(), 'show', $item);
    $thumbnailLink = !(item_has_thumbnail($item)) ? '' : link_to_item(item_thumbnail(array(), 0, $item), array(), 'show', $item);
    $description = item('Dublin Core', 'Description', array('snippet'=>150), $item);
    return '<div class="' . $markerHtmlClassName . '"><p class="geolocation_marker_title">' . $titleLink . '</p>' . $thumbnailLink . '<p>' . $description . '</p></div>';
}

/*
* Code for autcompleting the
*/
function geolocation_autocomplete($key = null){
	if (!$key) {
        $key = get_option('geolocation_gmaps_key') ? get_option('geolocation_gmaps_key') : 'AIzaSyD6zj4P4YxltcYJZsRVUvTqG_bT1nny30o';
    }
	$lang = "nl";

	echo '<script src="https://maps.googleapis.com/maps/api/js?sensor=false&libraries=places&key=' . $key . '&language='.$lang.'"></script>';
	?>
	
    <script>
	function reset(){
		resetTextareas();
		jQuery(".maintextinput").val("");
		initialize();
	}
	
	function resetTextareas(){
		jQuery(".geotextinput").val("");
		jQuery("#planetary_body").val("Aarde");
	}
	
	function initialize() {//function initialize() {
		var mapOptions = {
			center: new google.maps.LatLng(52.132633, 5.2912659999999505),
			zoom: 7,
			mapTypeId: google.maps.MapTypeId.ROADMAP
		};
		var map = new google.maps.Map(document.getElementById('map_canvas'), mapOptions);

		var options = {
			types: []
		};

		var input = document.getElementById('geolocation_address');
		var autocomplete = new google.maps.places.Autocomplete(input, options);

		autocomplete.bindTo('bounds', map);

		var infowindow = new google.maps.InfoWindow();
		var marker = new google.maps.Marker({
			map: map
		});

		google.maps.event.addListener(autocomplete, 'place_changed', function() {
			infowindow.close();
			var place = autocomplete.getPlace();
			if (place.geometry.viewport) {
				map.fitBounds(place.geometry.viewport);
			} else {
				map.setCenter(place.geometry.location);
				map.setZoom(15);  // Why 17? Because it looks good.
			}
			var image = new google.maps.MarkerImage(
				place.icon,
				new google.maps.Size(40, 80),
				new google.maps.Point(0, 0),
				new google.maps.Point(17, 34),
				new google.maps.Size(30, 30));
				marker.setIcon(image);
				marker.setPosition(place.geometry.location);
			var lat = place.geometry.location.lat();
			var lng = place.geometry.location.lng();
			var total = "";
			if (place.address_components) {
				for(var i in place.address_components) {
					total = total + place.address_components[i].types[0] + " - " + place.address_components[i].long_name + "<br>"
				}
			}
			infowindow.setContent('<div><strong>' + place.formatted_address + '</strong><br>' + total + "<br>" + lat + "," + lng);
			infowindow.open(map, marker);
			if (place.address_components) {
				resetTextareas();
//				$("#latitude").val(lat);
//				$("#longitude").val(lng);
				for(var i in place.address_components) {
					var value = (place.address_components[i] && place.address_components[i].long_name || '');
//					jQuery('#geolocation-latitude').val(gLatLng.lat());
					jQuery("#"+place.address_components[i].types[0]).val(value);
				}
				jQuery("#planetary_body").val("Aarde");
//				$("#planetary_body").val("Aarde"); 

			}
		});
	}
	google.maps.event.addDomListener(window, 'load', initialize);
	</script>
<?php
}


/**
 * Returns the form code for geographically searching for items
 * @param Item $item
 * @param int $width
 * @param int $height
 * @return string
 **/
function geolocation_map_form($item, $width = '100%', $height = '410px', $label = 'Find a Location by Address:', $confirmLocationChange = true,  $post = null)
{
    $ht = '';
	
    $center = geolocation_get_center();
    $center['show'] = false;

    $location = geolocation_get_location_for_item($item, true);
    
    if ($post === null) {
        $post = $_POST;
    }
        
    $usePost = !empty($post) && !empty($post['geolocation']);
    if ($usePost) {
        $lng  = (double) @$post['geolocation']['longitude'];
        $lat  = (double) @$post['geolocation']['latitude'];
        $zoom = (int) @$post['geolocation']['zoom_level'];
        $addr = @$post['geolocation']['address'];
        $planetary_body = @$post['geolocation']['planetary_body'];
        $continent = @$post['geolocation']['continent'];
        $country = @$post['geolocation']['country'];
        $administrative_area_level_1 = @$post['geolocation']['administrative_area_level_1'];
        $administrative_area_level_2 = @$post['geolocation']['administrative_area_level_2'];
        $locality = @$post['geolocation']['locality'];
        $sublocality = @$post['geolocation']['sublocality'];
        $route = @$post['geolocation']['route'];
        $point_of_interest = @$post['geolocation']['point_of_interest'];
        $establishment = @$post['geolocation']['establishment'];
        $street_number = @$post['geolocation']['street_number'];
        $postal_code = @$post['geolocation']['postal_code'];
        $postal_code_prefix = @$post['geolocation']['postal_code_prefix'];
    } else {
        if ($location) {
            $lng  = (double) $location['longitude'];
            $lat  = (double) $location['latitude'];
            $zoom = (int) $location['zoom_level'];
            $addr = $location['address'];
	        $planetary_body = $location['planetary_body'];
	        $continent = $location['continent'];
	        $country = $location['country'];
	        $administrative_area_level_1 = $location['administrative_area_level_1'];
	        $administrative_area_level_2 = $location['administrative_area_level_2'];
	        $locality = $location['locality'];
	        $sublocality = $location['sublocality'];
	        $route = $location['route'];
	        $point_of_interest = $location['point_of_interest'];
	        $establishment = $location['establishment'];
	        $street_number = $location['street_number'];
	        $postal_code = $location['postal_code'];
	        $postal_code_prefix = $location['postal_code_prefix'];
        } else {
            $lng = $lat = $zoom = $addr = '';
        }
    }
    ob_start();
	geolocation_autocomplete($key);
?>
<div id="location_form">
    <label style="display:inline; float:none; vertical-align:baseline;"><?php echo html_escape($label); ?></label>
    <input type="text" name="geolocation[address]" id="geolocation_address" size="60" value="<?php echo $addr; ?>" class="maintextinput" onKeypress="resetTextareas();"/>

    <button type="button" style="margin-bottom: 18px; float:none;" name="geolocation_find_location_by_address" id="geolocation_find_location_by_address">Find</button>
	<button type="button" value="rst" onClick="reset();">Reset</button>

</div>
<?php
    $options = array();
    $options['form'] = array('id' => 'location_form', 
                             'posted' => $usePost);
    if ($location or $usePost) {
        $options['point'] = array('latitude' => $lat, 
                                  'longitude' => $lng, 
                                  'zoomLevel' => $zoom);
    }
    
    $options['confirmLocationChange'] = $confirmLocationChange;
    
    $center = js_escape($center);
    $options = js_escape($options);
    $divId = 'omeka-map-form';    
?>

    <div id="<?php echo html_escape($divId); ?>" style="width: <?php echo $width; ?>; height: <?php echo $height; ?>;"></div>
    <div id="map_canvas"></div>
	<?php echo label('geolocation-coodinates', 'Coordinates / Map settings'); ?>
    <input type="hidden" name="geolocation[map_type]" value="Google Maps v<?php echo GOOGLE_MAPS_API_VERSION;  ?>" />
    <input name="geolocation[latitude]" id="latitude" size="12" value="<?php echo $lat; ?>" class="coordinput"/>
    <input name="geolocation[longitude]" id="longitude" size="12" value="<?php echo $lng; ?>" class="coordinput"/>
    <input name="geolocation[zoom_level]" id="zoom_level" size="2" value="<?php echo $zoom; ?>" class="coordinput"/><br>
	<?php echo label('geolocation-address', 'Address'); ?>
    <input name="geolocation[route]" id="route" rows="1" size="20" value="<?php echo $route; ?>" class="geotextinput"/>
    <input name="geolocation[street_number]" id="street_number" rows="1" size="4" value="<?php echo $street_number; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-pc', 'Postal code'); ?>
	<input name="geolocation[postal_code]" id="postal_code" rows="1" size="12" value="<?php echo $postal_code; ?>" class="geotextinput"/>
	<input name="geolocation[postal_code_prefix]" id="postal_code_prefix" rows="1" size="12" value="<?php echo $postal_code_prefix; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-sublocality', 'Sublocality'); ?>
    <input name="geolocation[sublocality]" id="sublocality" rows="1" size="28" value="<?php echo $sublocality; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-place', 'Place'); ?>
    <input name="geolocation[locality]" id="locality" rows="1" size="28" value="<?php echo $locality; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-nf', 'Natural feature'); ?>
    <input name="geolocation[natural_feature]" id="natural_feature" rows="1" size="28" value="<?php echo $natural_feature; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-establishment', 'Establishment'); ?>
    <input name="geolocation[establishment]" id="establishment" rows="1" size="28" value="<?php echo $establishment; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-county', 'County'); ?>
    <input name="geolocation[administrative_area_level_2]" id="administrative_area_level_2" rows="1" size="28" value="<?php echo $administrative_area_level_2; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-province', 'Province'); ?>
    <input name="geolocation[administrative_area_level_1]" id="administrative_area_level_1" rows="1" size="28" value="<?php echo $administrative_area_level_1; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-country', 'Country'); ?>
    <input name="geolocation[country]" id="country" rows="1" size="28" value="<?php echo $country; ?>" class="geotextinput"/><br>
	<?php echo label('geolocation-planet', 'Planetary_body'); ?>
    <input name="geolocation[planetary_body]" id="planetary_body" size="28" value="<?php echo $planetary_body; ?>" class="geotextinput"/><br>

    <script type="text/javascript">
        //<![CDATA[
        var anOmekaMapForm = new OmekaMapForm(<?php echo js_escape($divId); ?>, <?php echo $center; ?>, <?php echo $options; ?>);
        jQuery(document).bind('omeka:tabselected', function () {
            anOmekaMapForm.resize();
        });
        //]]>
    </script>
<?php
    $ht .= ob_get_contents();
    ob_end_clean();

    return $ht;
}

/**
 * Returns the html for the marker CSS
 * @return string
 **/
function geolocation_marker_style()
{
    $html = '<link rel="stylesheet" href="'.css("geolocation-marker").'" />';
    return $html;
}

/**
 * NEEDS SOME SEVERE EXTENSION - reveal place names and other stories present
 * Shows a small map on the admin show page in the secondary column
 * @param Item $item
 * @return void
 **/
function geolocation_admin_show_item_map($item)
{
    $location = geolocation_get_location_for_item($item, true);
    if ($location) {
		echo geolocation_scripts() . '<div class="info-panel">' . '<h2>Geolocation</h2>' . geolocation_google_map_for_item($item,'224px','270px');// . "</div>";
#		echo '<pre class="info-panel">' . '<h2>Location info</h2>';
		geolocation_show_location_details($location);
/*		foreach(($location->toArray()) as $key => $inf){
			if ($inf){
				echo "<H4>" . $key . "</H4>";
				print $inf . "<br>";
			}
		}*/
		print "</div>";
	}
}

function geolocation_show_location_details($location){
	echo "<table class=\"location\">";
	foreach(($location->toArray()) as $key => $inf){
		if ($key == "administrative_area_level_2"){
			echo "<tr><td>County</td><td>" . $inf . "</td></tr>";
		}		
		else if ($key == "administrative_area_level_1"){
			echo "<tr><td>Province</td><td>" . $inf . "</td></tr>";
		}
		else if ($key == "planetary_body"){
			echo "<tr><td>Planet</td><td>" . $inf . "</td></tr>";
		}
		else if ($key == "item_id" or $key == "id" or $key == "zoom_level" or $key == "map_type" or $key == "longitude" or $key == "latitude"){ None; }
		else if ($inf){
			echo "<tr><td>".$key."</td><td>" . $inf . "</td></tr>";
		}
	}
	echo "</table>";
}

function geolocation_public_show_item_map($width = null, $height = null, $item = null)
{
    if (!$width) {
        $width = get_option('geolocation_item_map_width') ? get_option('geolocation_item_map_width') : '100%';
    }
    
    if (!$height) {
        $height = get_option('geolocation_item_map_height') ? get_option('geolocation_item_map_height') : '400px';
    }
    
    if (!$item) {
        $item = get_current_item();
    }

    $location = geolocation_get_location_for_item($item, true);

    if ($location) {
        echo geolocation_scripts()
           . '<div class=\"geo_public\"><h2>Geolocation</h2>'
           . geolocation_google_map_for_item();
		geolocation_show_location_details($location);
		echo "</div>";
    }
}

function geolocation_autocomplete_code($contributionType)
{
    if (get_option('geolocation_add_map_to_contribution_form') == '1') {
        $html = '<div id="geolocation_autocomplete">'
              . geolocation_autocomplete()
              . '</div>';
        echo $html;
    }
}

function geolocation_append_contribution_form($contributionType)
{
    if (get_option('geolocation_add_map_to_contribution_form') == '1') {
        $html = '<div id="geolocation_contribution">'
              . geolocation_map_form(null, '500px', '410px', 'Find A Geographic Location For The ' . $contributionType->display_name . ':', false)
              . '</div>'
              . '<script type="text/javascript">'
              . 'jQuery("#contribution-type-form").bind("contribution-form-shown", function () {anOmekaMapForm.resize();});'
              . '</script>';
        echo $html;
    }
}

function geolocation_save_contribution_form($contributionType, $item, $post)
{
    if (get_option('geolocation_add_map_to_contribution_form') == '1') {
        geolocation_save_location($item);
    }
}

function geolocation_item_browse_sql($select, $params)
{
    // It would be nice if the item_browse_sql hook also passed in the request 
    // object.
    if (($request = Omeka_Context::getInstance()->getRequest())) {

        $db = get_db();

        // Get the address, latitude, longitude, and the radius from parameters
        $address = trim($request->getParam('geolocation-address'));
        $currentLat = trim($request->getParam('geolocation-latitude'));
        $currentLng = trim($request->getParam('geolocation-longitude'));
        $radius = trim($request->getParam('geolocation-radius'));

        if ($request->get('only_map_items') || $address != '') {
            //INNER JOIN the locations table
            $select->joinInner(array('l' => $db->Location), 'l.item_id = i.id', 
                array('latitude', 'longitude', 'address'));
        }
        
        // Limit items to those that exist within a geographic radius if an address and radius are provided 
        if ($address != '' && is_numeric($currentLat) && is_numeric($currentLng) && is_numeric($radius)) {
            // SELECT distance based upon haversine forumula
            $select->columns('3956 * 2 * ASIN(SQRT(  POWER(SIN(('.$currentLat.' - l.latitude) * pi()/180 / 2), 2) + COS('.$currentLat.' * pi()/180) *  COS(l.latitude * pi()/180) *  POWER(SIN(('.$currentLng.' -l.longitude) * pi()/180 / 2), 2)  )) as distance');
            // WHERE the distance is within radius miles of the specified lat & long
             $select->where('(latitude BETWEEN '.$currentLat.' - ' . $radius . '/69 AND ' . $currentLat . ' + ' . $radius .  '/69)
             AND (longitude BETWEEN ' . $currentLng . ' - ' . $radius . '/69 AND ' . $currentLng  . ' + ' . $radius .  '/69)');
            //ORDER by the closest distances
            $select->order('distance');
        }
    
        // This would be better as a filter that actually manipulated the 
        // 'per_page' value via this plugin. Until then, we need to hack the 
        // LIMIT clause for the SQL query that determines how many items to 
        // return.
        if ($request->get('use_map_per_page')) {            
            // If the limit of the SQL query is 1, we're probably doing a 
            // COUNT(*)
            $limitCount = $select->getPart(Zend_Db_Select::LIMIT_COUNT);
            if ($limitCount != 1) {                
                $select->reset(Zend_Db_Select::LIMIT_COUNT);
                $select->reset(Zend_Db_Select::LIMIT_OFFSET);
                $pageNum = $request->get('page') or $pageNum = 1;                
                $select->limitPage($pageNum, geolocation_get_map_items_per_page());
            }
        }
    }
}

function geolocation_admin_append_to_advanced_search() 
{   
    // Get the request object
    $request = Omeka_Context::getInstance()->getRequest();
    
    if ($request->getControllerName() == 'map' && $request->getActionName() == 'browse') {
        $html = geolocation_append_to_advanced_search('search');
    } else if ($request->getControllerName() == 'items' && $request->getActionName() == 'advanced-search') {
        $html = geolocation_scripts()
              . geolocation_append_to_advanced_search();
    }
    
    echo $html;
}

function geolocation_public_append_to_advanced_search() 
{
    $html = geolocation_scripts()
          . geolocation_append_to_advanced_search();
    echo $html;
}

function geolocation_append_to_advanced_search($searchFormId = 'advanced-search-form', $searchButtonId = 'submit_search_advanced')
{
    // Get the request object
    $request = Omeka_Context::getInstance()->getRequest();

    // Get the address, latitude, longitude, and the radius from parameters

#ADD ALL LAYERS
    $administrative_area_level_1 = trim($request->getParam('geolocation-administrative_area_level_1'));
    $locality = trim($request->getParam('geolocation-locality'));
    $address = trim($request->getParam('geolocation-address'));

    $currentLat = trim($request->getParam('geolocation-latitude'));
    $currentLng = trim($request->getParam('geolocation-longitude'));
    $radius = trim($request->getParam('geolocation-radius'));
    
    if (empty($radius)) {
        $radius = 10; // 10 miles
    }
    
    $ht = '';
    ob_start();
?> 
    <div class="field">
	    <?php echo label('geolocation-address', 'Geographic Address'); ?>
	    <div class="inputs">
			<?php echo label('geolocation-locality', 'Geographic place name'); ?>
	        <?php echo text(array('name'=>'geolocation-locality','size' => '40','id'=>'geolocation-locality','class'=>'textinput'),$locality); ?>
			<br>
			<?php echo label('geolocation-administrative_area_level_2', 'County'); ?>
	        <?php echo text(array('name'=>'geolocation-administrative_area_level_2','size' => '40','id'=>'geolocation-administrative_area_level_2','class'=>'textinput'),$administrative_area_level_2); ?>
			<br>
			<?php echo label('geolocation-administrative_area_level_1', 'Province'); ?>
	        <?php echo text(array('name'=>'geolocation-administrative_area_level_1','size' => '40','id'=>'geolocation-administrative_area_level_1','class'=>'textinput'),$administrative_area_level_1); ?>
			<br>
			<?php echo label('geolocation-address', 'Other'); ?>
	        <?php echo text(array('name'=>'geolocation-address','size' => '40','id'=>'geolocation-address','class'=>'textinput'),$address); ?>
            <?php echo hidden(array('name'=>'geolocation-latitude','id'=>'geolocation-latitude'),$currentLat); ?>
            <?php echo hidden(array('name'=>'geolocation-longitude','id'=>'geolocation-longitude'),$currentLng); ?>
	    </div>
	</div>
	
	<div class="field">
		<?php echo label('geolocation-radius','Geographic Radius (miles)'); ?>
		<div class="inputs">
	        <?php echo text(array('name'=>'geolocation-radius','size' => '40','id'=>'geolocation-radius','class'=>'textinput'),$radius); ?>
	    </div>
	</div>
	
	<script type="text/javascript">
	    jQuery(document).ready(function() {
    	    jQuery('#<?php echo $searchButtonId; ?>').click(function(event) {
    	            	        
    	        // Find the geolocation for the address
    	        var address = jQuery('#geolocation-address').val();
                
                if (jQuery.trim(address).length > 0) {
                    var geocoder = new google.maps.Geocoder();	        
                    geocoder.geocode({'address': address}, function(results, status) {
                        // If the point was found, then put the marker on that spot
                		if (status == google.maps.GeocoderStatus.OK) {
                			var gLatLng = results[0].geometry.location;
                	        // Set the latitude and longitude hidden inputs
                	        jQuery('#geolocation-latitude').val(gLatLng.lat());
                	        jQuery('#geolocation-longitude').val(gLatLng.lng());
                            jQuery('#<?php echo $searchFormId; ?>').submit();
                		} else {
                		  	// If no point was found, give us an alert
                		    alert('Error: "' + address + '" was not found!');
                		}
                    });
                    
                    event.stopImmediatePropagation();
        	        return false;
                }                
    	    });
	    });
	</script>
	
<?php
    $ht .= ob_get_contents();
    ob_end_clean();
    return $ht;
}
