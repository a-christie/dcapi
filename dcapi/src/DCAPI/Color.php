<?php
/*
 *		Routines for generating unique colors
 */

namespace DCAPI;

class Color {

    private $colors = array("#1f77b4", "#ff7f0e", "#2ca02c", "#d62728", "#9467bd", "#8c564b", "#e377c2", "#7f7f7f", "#bcbd22", "#17becf");
    private $tempColors = array("#1f77b4", "#ff7f0e", "#2ca02c", "#d62728", "#9467bd", "#8c564b", "#e377c2", "#7f7f7f", "#bcbd22", "#17becf");
    private $lastDirection = false;

    public function __construct() {
    	return;
    }

    public function nextColor() {
        if ($this->tempColors && count($this->tempColors) > 0) {
            $color = $this->tempColors[0];
            if (count($this->tempColors) === 1) {
                $this->tempColors = null;
            } else {
                $this->tempColors = array_splice($this->tempColors, 1);
            }
            return $color; 
        } else {
            $this->tempColors = $this->generateColors(!$this->lastDirection, 25);
            $this->lastDirection = !$this->lastDirection;
            return $this->nextColor();
        }
    }

    private function generateColors($direction, $factor) {
        $colors = [];
        for ($i=0; $i < count($this->colors); $i++) { 
            if (direction) {
                array_push($colors, $this->adjustBrightness($this->colors[$i], $factor));
            } else {
                array_push($colors, $this->adjustBrightness($this->colors[$i], -1 * $factor));
            }
        }
        return $colors;
    }

    private function adjustBrightness($hex, $steps) {
        // Steps should be between -255 and 255. Negative = darker, positive = lighter
        $steps = max(-255, min(255, $steps));

        // Normalize into a six character long hex string
        $hex = str_replace('#', '', $hex);
        if (strlen($hex) == 3) {
            $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2);
        }

        // Split into three parts: R, G and B
        $color_parts = str_split($hex, 2);
        $return = '#';

        foreach ($color_parts as $color) {
            $color   = hexdec($color); // Convert to decimal
            $color   = max(0,min(255,$color + $steps)); // Adjust color
            $return .= str_pad(dechex($color), 2, '0', STR_PAD_LEFT); // Make two char hex code
        }
        return $return;
    }

}

?>