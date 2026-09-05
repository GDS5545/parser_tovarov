<?php
/**
 * Synonym tables for attribute-key normalization (spec §5) and unit
 * normalization (spec §12). Deliberately not exhaustive — the point isn't
 * to enumerate every possible label but to collapse the handful of
 * spellings that show up constantly across RU/KZ/EN stores so
 * "Voltage" / "Напряжение" / "Напряжение питания" land on one
 * pa_voltage attribute instead of three.
 *
 * @package Uws\Normalizer
 */

namespace Uws\Normalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AttributeDictionary {

	/**
	 * canonical slug => [display label, [synonym, ...]]. Synonyms are
	 * matched case-insensitively against the raw label with punctuation
	 * stripped.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public static function canonical_attributes() {
		return array(
			'brand'        => array( 'Brand', array( 'brand', 'бренд', 'производитель', 'manufacturer' ) ),
			'model'        => array( 'Model', array( 'model', 'модель' ) ),
			'material'     => array( 'Material', array( 'material', 'материал' ) ),
			'color'        => array( 'Color', array( 'color', 'colour', 'цвет' ) ),
			'voltage'      => array( 'Voltage', array( 'voltage', 'напряжение', 'напряжение питания' ) ),
			'power'        => array( 'Power', array( 'power', 'мощность' ) ),
			'capacity'     => array( 'Capacity', array( 'capacity', 'объем', 'объём', 'емкость', 'ёмкость' ) ),
			'diameter'     => array( 'Diameter', array( 'diameter', 'диаметр' ) ),
			'width'        => array( 'Width', array( 'width', 'ширина' ) ),
			'height'       => array( 'Height', array( 'height', 'высота' ) ),
			'length'       => array( 'Length', array( 'length', 'длина' ) ),
			'weight'       => array( 'Weight', array( 'weight', 'вес', 'масса' ) ),
			'size'         => array( 'Size', array( 'size', 'размер' ) ),
			'frequency'    => array( 'Frequency', array( 'frequency', 'частота' ) ),
			'current'      => array( 'Current', array( 'current', 'ток', 'сила тока' ) ),
			'pressure'     => array( 'Pressure', array( 'pressure', 'давление' ) ),
			'temperature'  => array( 'Temperature', array( 'temperature', 'температура' ) ),
			'warranty'     => array( 'Warranty', array( 'warranty', 'гарантия' ) ),
			'country'      => array( 'Country of origin', array( 'country of origin', 'country', 'страна производства', 'страна' ) ),
		);
	}

	/**
	 * Unit spelling variants => canonical unit symbol.
	 *
	 * @return array<string,string>
	 */
	public static function unit_aliases() {
		return array(
			'volt'  => 'V',
			'volts' => 'V',
			'в'     => 'V',
			'w'     => 'W',
			'watt'  => 'W',
			'watts' => 'W',
			'вт'    => 'W',
			'kw'    => 'kW',
			'квт'   => 'kW',
			'kg'    => 'kg',
			'кг'    => 'kg',
			'g'     => 'g',
			'г'     => 'g',
			'lb'    => 'lb',
			'mm'    => 'mm',
			'мм'    => 'mm',
			'cm'    => 'cm',
			'см'    => 'cm',
			'm'     => 'm',
			'м'     => 'm',
			'l'     => 'L',
			'л'     => 'L',
			'ml'    => 'ml',
			'мл'    => 'ml',
			'hz'    => 'Hz',
			'гц'    => 'Hz',
			'a'     => 'A',
			'а'     => 'A',
			'bar'   => 'bar',
			'бар'   => 'bar',
			'psi'   => 'psi',
			'°c'    => '°C',
			'c'     => '°C',
			'%'     => '%',
		);
	}
}
