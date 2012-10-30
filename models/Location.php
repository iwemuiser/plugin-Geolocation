<?php
require_once 'LocationTable.php';
/**
 * Location
 * @package: Omeka
 */
class Location extends Omeka_Record
{
    public $item_id;
    public $latitude;
    public $longitude;
    public $zoom_level;
    public $map_type;

	public $point_of_interest;             #spot
	public $route;                         #straat
	public $sublocality;                   #stadsdeel
    public $locality;                      #plaats
    public $administrative_area_level_2;   #gemeente
    public $administrative_area_level_1;   #povincie
    public $country;                       #land
    public $continent;                     
    public $planetary_body;

    public $address;						#original search term
    
    protected function _validate()
    {
        if (empty($this->item_id)) {
            $this->addError('item_id', 'Location requires an item id.');
        }
    }
}