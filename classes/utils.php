<?php
/**
 * Copyright (C) 2019 Petr Hucik <petr@getdatakick.com>
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@getdatakick.com so we can send you a copy immediately.
 *
 * @author    Petr Hucik <petr@getdatakick.com>
 * @copyright 2017-2019 Petr Hucik
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace AvardaPayments;

use Address;
use Cart;
use Country;
use Customer;
use Exception;
use PrestaShopLogger;
use Tools;
use Validate;

class Utils
{
    /**
     * @param Cart $cart
     *
     * @return array
     *
     * @throws Exception
     */
    public static function getCartInfo(Cart $cart, $deliveryDescription = null)
    {
        $items = array_map(function ($product) {
            return [
                'description' => static::maxChars($product['quantity'] . ' x ' . $product['name'], 35),
                'amount' => strval(static::roundPrice($product['total_wt'])),
                'taxAmount' => strval(static::roundPrice($product['total_wt']) - static::roundPrice($product['total'])),
            ];
        }, $cart->getProducts());

        $discount = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        if ($discount > 0) {
            $items[] = [
                'description' => 'Discount',
                'amount' => strval(-1 * $discount),
                'taxAmount' => 0,
            ];
        }

        $deliveryItemCost = $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
        $deliveryItem = [
            'description' => $deliveryDescription,
            'amount' => strval($deliveryItemCost),
            'taxAmount' => 0,
        ];
        array_push($items, $deliveryItem);

        return [
            'price' => static::roundPrice($cart->getOrderTotal()),
            'items' => $items,
        ];
    }

    /**
     * @param Cart $cart
     *
     * @return array
     */
    public static function getCustomerInfo(Cart $cart)
    {
        $id = (int) $cart->id_customer;
        if (!$id) {
            return [];
        }
        $customer = new Customer($id);
        if (!Validate::isLoadedObject($customer)) {
            return [];
        }
        $delivery = new Address($cart->id_address_delivery);
        $invoice = new Address($cart->id_address_invoice);
        $data = [
            'Mail' => static::maxChars($customer->email, 60),
            'Phone' => static::maxChars($invoice->phone ? $invoice->phone : $invoice->phone_mobile, 15),
        ];
        $data = array_merge($data, static::addressInfo($delivery, 'Delivery'));
        $data = array_merge($data, static::addressInfo($invoice, 'Invoicing'));

        return $data;
    }

    /**
     * @param Address $address
     * @param $prefix
     *
     * @return array
     */
    public static function addressInfo(Address $address, $prefix)
    {
        if (!Validate::isLoadedObject($address)) {
            return [];
        }

        return array_filter([
            $prefix . 'FirstName' => $address->firstname,
            $prefix . 'LastName' => $address->lastname,
            $prefix . 'AddressLine1' => $address->address1,
            $prefix . 'AddressLine2' => $address->address2,
            $prefix . 'Zip' => $address->postcode,
            $prefix . 'City' => $address->city,
        ]);
    }

    /**
     * @param $cartInfo
     *
     * @return string
     */
    public static function getCartInfoSignature($cartInfo)
    {
        return $cartInfo['price'] . ',' . count($cartInfo['items']);
    }

    /**
     * @param $type
     * @param $paymentInfo
     *
     * @return array
     */
    public static function extractAddressInfo($type, $checkoutSite, $paymentInfo, $purchaseMode)
    {
        // future reference - PrestaShopLogger
        // PrestaShopLogger::addLog('extractAddressInfo()' . $alpha2, 1, null, null, null, true);
        $country = '';
        $id_country = 0;

        if (isset($checkoutSite->countryCode) && !empty($checkoutSite->countryCode)) {
            // country code is set, change it into alpha 2
            $country = $checkoutSite->countryCode;
            $alpha2 = static::toAlpha2($country);
            $id_country = Country::getByIso($alpha2);
        } elseif (isset($paymentInfo->$type->country) && !empty($paymentInfo->$type->country)) {
            // get alpha2 by country name instead
            $country = $paymentInfo->$type->country;
            $alpha2 = static::nameToAlpha2($country);
            $id_country = Country::getByIso($alpha2);
        }
        $company = '';
        $vat_number = '';

        // For B2B there is no first and last name, just company name. Can't be empty in PrestaShop.
        $firstname = '-';
        $lastName = '-';

        if ($purchaseMode === 'B2B') {
            if ($type === 'invoicingAddress') {
                if (isset($paymentInfo->invoicingAddress->name) && !empty($paymentInfo->invoicingAddress->name)) {
                    $company = $paymentInfo->invoicingAddress->name;
                }
            }

            if (isset($paymentInfo->customerInfo->firstName) && !empty($paymentInfo->customerInfo->firstName)) {
                $firstname = $paymentInfo->customerInfo->firstName;
            }

            if (isset($paymentInfo->customerInfo->lastName) && !empty($paymentInfo->customerInfo->lastName)) {
                $lastName = $paymentInfo->customerInfo->lastName;
            }

            /* There seems to be VAT number in normal checkout form, do we need it?
            if(isset($paymentInfo['CompanyName'])) {
                $vat_number = $paymentInfo['OrganizationNumber'];
            }
            */

            // B2B has different fields for invoicing and delivery address
            if ($type === 'invoicingAddress') {
                return [
                    'address1' => $paymentInfo->$type->address1,
                    'address2' => $paymentInfo->$type->address2,
                    'city' => $paymentInfo->$type->city,
                    'firstname' => $firstname,
                    'lastname' => $lastName,
                    'postcode' => $paymentInfo->$type->zip,
                    'phone' => $paymentInfo->userInputs->phone,
                    'id_country' => $id_country,
                    'company' => $company,
                    'vat_number' => $vat_number,
                ];
            } else {
                return [
                    'address1' => $paymentInfo->$type->address1,
                    'address2' => $paymentInfo->$type->address2,
                    'city' => $paymentInfo->$type->city,
                    'firstname' => $firstname,
                    'lastname' => $lastName,
                    'postcode' => $paymentInfo->$type->zip,
                    'phone' => $paymentInfo->userInputs->phone,
                    'id_country' => $id_country,
                    'company' => $company,
                    'vat_number' => $vat_number,
                ];
            }
        } else {
            // B2C address
            return [
                'address1' => $paymentInfo->$type->address1,
                'address2' => $paymentInfo->$type->address2,
                'city' => $paymentInfo->$type->city,
                'firstname' => $paymentInfo->$type->firstName,
                'lastname' => $paymentInfo->$type->lastName,
                'postcode' => $paymentInfo->$type->zip,
                'phone' => $paymentInfo->userInputs->phone,
                'id_country' => $id_country,
                'company' => $company,
                'vat_number' => $vat_number,
            ];
        }
    }

    /**
     * @param $addressInfo
     *
     * @return bool
     */
    public static function validAddressInfo($addressInfo)
    {
        return
            $addressInfo['id_country'] &&
            $addressInfo['address1'] &&
            $addressInfo['city'] &&
            $addressInfo['postcode'] &&
            $addressInfo['firstname'] &&
            $addressInfo['lastname']
        ;
    }

    /**
     * @param array $address
     * @param $addressInfo
     *
     * @return bool
     */
    public static function addressMatches($address, $addressInfo)
    {
        foreach ($addressInfo as $key => $value) {
            $val1 = static::emptyIfNull($address[$key]);
            $val2 = static::emptyIfNull($value);
            if ($val1 != $val2) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $amount
     *
     * @return float
     */
    public static function roundPrice($amount)
    {
        return Tools::ps_round($amount, 2);
    }

    /**
     * @param $str
     * @param $max
     *
     * @return string
     */
    public static function maxChars($str, $max)
    {
        if ($str) {
            if (mb_strlen($str) > $max) {
                return mb_substr($str, 0, $max);
            }
        }

        return $str;
    }

    /**
     * @param $addresses
     *
     * @return string
     */
    public static function getAddressAlias($addresses)
    {
        $max = 0;
        foreach ($addresses as $address) {
            $matches = null;
            if (preg_match('/Avarda ([0-9]+)$/', $address['alias'], $matches)) {
                $max = max($max, $matches[1]);
            }
        }
        ++$max;

        return "Avarda $max";
    }

    /**
     * @param string $str
     *
     * @return string
     */
    private static function emptyIfNull($str)
    {
        return $str ? $str : '';
    }

    public function truncateValue($string, $length, $name = false)
    {
        // if string is name, remove numbers and non-word characters except '-'
        if ($name) {
            $string = (string) preg_replace('([^\w.-åäöÅÄÖ]|\d|_)', '', $string);
            $string = trim($string);
        }

        if (Tools::strlen($string) > $length) {
            $string = Tools::substr($string, 0, $length);
        }

        return $string;
    }

    private static function toAlpha2($alpha3)
    {
        $alpha3to2 = [
            'ABW' => 'AW',
            'AFG' => 'AF',
            'AGO' => 'AO',
            'AIA' => 'AI',
            'ALB' => 'AL',
            'AND' => 'AD',
            'ANT' => 'AN',
            'ARE' => 'AE',
            'ARG' => 'AR',
            'ARM' => 'AM',
            'ASM' => 'AS',
            'ATA' => 'AQ',
            'ATF' => 'TF',
            'ATG' => 'AG',
            'AUS' => 'AU',
            'AUT' => 'AT',
            'AZE' => 'AZ',
            'BDI' => 'BI',
            'BEL' => 'BE',
            'BEN' => 'BJ',
            'BFA' => 'BF',
            'BGD' => 'BD',
            'BGR' => 'BG',
            'BHR' => 'BH',
            'BHS' => 'BS',
            'BIH' => 'BA',
            'BLR' => 'BY',
            'BLZ' => 'BZ',
            'BMU' => 'BM',
            'BOL' => 'BO',
            'BRA' => 'BR',
            'BRB' => 'BB',
            'BRN' => 'BN',
            'BTN' => 'BT',
            'BVT' => 'BV',
            'BWA' => 'BW',
            'CAF' => 'CF',
            'CAN' => 'CA',
            'CCK' => 'CC',
            'CHE' => 'CH',
            'CHL' => 'CL',
            'CHN' => 'CN',
            'CIV' => 'CI',
            'CMR' => 'CM',
            'COD' => 'CD',
            'COG' => 'CG',
            'COK' => 'CK',
            'COL' => 'CO',
            'COM' => 'KM',
            'CPV' => 'CV',
            'CRI' => 'CR',
            'CUB' => 'CU',
            'CXR' => 'CX',
            'CYM' => 'KY',
            'CYP' => 'CY',
            'CZE' => 'CZ',
            'DEU' => 'DE',
            'DJI' => 'DJ',
            'DMA' => 'DM',
            'DNK' => 'DK',
            'DOM' => 'DO',
            'DZA' => 'DZ',
            'ECU' => 'EC',
            'EGY' => 'EG',
            'ERI' => 'ER',
            'ESH' => 'EH',
            'ESP' => 'ES',
            'EST' => 'EE',
            'ETH' => 'ET',
            'FIN' => 'FI',
            'FJI' => 'FJ',
            'FLK' => 'FK',
            'FRA' => 'FR',
            'FRO' => 'FO',
            'FSM' => 'FM',
            'GAB' => 'GA',
            'GBR' => 'GB',
            'GEO' => 'GE',
            'GGY' => 'GG',
            'GHA' => 'GH',
            'GIB' => 'GI',
            'GIN' => 'GN',
            'GLP' => 'GP',
            'GMB' => 'GM',
            'GNB' => 'GW',
            'GNQ' => 'GQ',
            'GRC' => 'GR',
            'GRD' => 'GD',
            'GRL' => 'GL',
            'GTM' => 'GT',
            'GUF' => 'GF',
            'GUM' => 'GU',
            'GUY' => 'GY',
            'HKG' => 'HK',
            'HMD' => 'HM',
            'HND' => 'HN',
            'HRV' => 'HR',
            'HTI' => 'HT',
            'HUN' => 'HU',
            'IDN' => 'ID',
            'IMN' => 'IM',
            'IND' => 'IN',
            'IOT' => 'IO',
            'IRL' => 'IE',
            'IRN' => 'IR',
            'IRQ' => 'IQ',
            'ISL' => 'IS',
            'ISR' => 'IL',
            'ITA' => 'IT',
            'JAM' => 'JM',
            'JEY' => 'JE',
            'JOR' => 'JO',
            'JPN' => 'JP',
            'KAZ' => 'KZ',
            'KEN' => 'KE',
            'KGZ' => 'KG',
            'KHM' => 'KH',
            'KIR' => 'KI',
            'KNA' => 'KN',
            'KOR' => 'KR',
            'KWT' => 'KW',
            'LAO' => 'LA',
            'LBN' => 'LB',
            'LBR' => 'LR',
            'LBY' => 'LY',
            'LCA' => 'LC',
            'LIE' => 'LI',
            'LKA' => 'LK',
            'LSO' => 'LS',
            'LTU' => 'LT',
            'LUX' => 'LU',
            'LVA' => 'LV',
            'MAC' => 'MO',
            'MAR' => 'MA',
            'MCO' => 'MC',
            'MDA' => 'MD',
            'MDG' => 'MG',
            'MDV' => 'MV',
            'MEX' => 'MX',
            'MHL' => 'MH',
            'MKD' => 'MK',
            'MLI' => 'ML',
            'MLT' => 'MT',
            'MMR' => 'MM',
            'MNE' => 'ME',
            'MNG' => 'MN',
            'MNP' => 'MP',
            'MOZ' => 'MZ',
            'MRT' => 'MR',
            'MSR' => 'MS',
            'MTQ' => 'MQ',
            'MUS' => 'MU',
            'MWI' => 'MW',
            'MYS' => 'MY',
            'MYT' => 'YT',
            'NAM' => 'NA',
            'NCL' => 'NC',
            'NER' => 'NE',
            'NFK' => 'NF',
            'NGA' => 'NG',
            'NIC' => 'NI',
            'NIU' => 'NU',
            'NLD' => 'NL',
            'NOR' => 'NO',
            'NPL' => 'NP',
            'NRU' => 'NR',
            'NZL' => 'NZ',
            'OMN' => 'OM',
            'PAK' => 'PK',
            'PAN' => 'PA',
            'PCN' => 'PN',
            'PER' => 'PE',
            'PHL' => 'PH',
            'PLW' => 'PW',
            'PNG' => 'PG',
            'POL' => 'PL',
            'PRI' => 'PR',
            'PRK' => 'KP',
            'PRT' => 'PT',
            'PRY' => 'PY',
            'PSE' => 'PS',
            'PYF' => 'PF',
            'QAT' => 'QA',
            'REU' => 'RE',
            'ROU' => 'RO',
            'RUS' => 'RU',
            'RWA' => 'RW',
            'SAU' => 'SA',
            'SDN' => 'SD',
            'SEN' => 'SN',
            'SGP' => 'SG',
            'SGS' => 'GS',
            'SHN' => 'SH',
            'SJM' => 'SJ',
            'SLB' => 'SB',
            'SLE' => 'SL',
            'SLV' => 'SV',
            'SMR' => 'SM',
            'SOM' => 'SO',
            'SPM' => 'PM',
            'SRB' => 'RS',
            'STP' => 'ST',
            'SUR' => 'SR',
            'SVK' => 'SK',
            'SVN' => 'SI',
            'SWE' => 'SE',
            'SWZ' => 'SZ',
            'SYC' => 'SC',
            'SYR' => 'SY',
            'TCA' => 'TC',
            'TCD' => 'TD',
            'TGO' => 'TG',
            'THA' => 'TH',
            'TJK' => 'TJ',
            'TKL' => 'TK',
            'TKM' => 'TM',
            'TLS' => 'TL',
            'TON' => 'TO',
            'TTO' => 'TT',
            'TUN' => 'TN',
            'TUR' => 'TR',
            'TUV' => 'TV',
            'TWN' => 'TW',
            'TZA' => 'TZ',
            'UGA' => 'UG',
            'UKR' => 'UA',
            'UMI' => 'UM',
            'URY' => 'UY',
            'USA' => 'US',
            'UZB' => 'UZ',
            'VAT' => 'VA',
            'VCT' => 'VC',
            'VEN' => 'VE',
            'VGB' => 'VG',
            'VIR' => 'VI',
            'VNM' => 'VN',
            'VUT' => 'VU',
            'WLF' => 'WF',
            'WSM' => 'WS',
            'YEM' => 'YE',
            'ZAF' => 'ZA',
            'ZMB' => 'ZM',
        ];
        if (isset($alpha3to2[$alpha3])) {
            return $alpha3to2[$alpha3];
        }

        return $alpha3;
    }

    private static function nameToAlpha2($name)
    {
        $nameToAlpha2 = [
            'Andorra' => 'AD',
            'United Arab Emirates' => 'AE',
            'Afghanistan' => 'AF',
            'Antigua and Barbuda' => 'AG',
            'Anguilla' => 'AI',
            'Albania' => 'AL',
            'Armenia' => 'AM',
            'Angola' => 'AO',
            'Antarctica' => 'AQ',
            'Argentina' => 'AR',
            'American Samoa' => 'AS',
            'Austria' => 'AT',
            'Australia' => 'AU',
            'Aruba' => 'AW',
            'Azerbaijan' => 'AZ',
            'Bosnia and Herzegovina' => 'BA',
            'Barbados' => 'BB',
            'Bangladesh' => 'BD',
            'Belgium' => 'BE',
            'Burkina Faso' => 'BF',
            'Bulgaria' => 'BG',
            'Bahrain' => 'BH',
            'Burundi' => 'BI',
            'Benin' => 'BJ',
            'Bermuda' => 'BM',
            'Brunei Darussalam' => 'BN',
            'Bolivia' => 'BO',
            'Brazil' => 'BR',
            'Bahamas' => 'BS',
            'Bhutan' => 'BT',
            'Bouvet Island' => 'BV',
            'Botswana' => 'BW',
            'Belarus' => 'BY',
            'Belize' => 'BZ',
            'Canada' => 'CA',
            'Cocos Islands' => 'CC',
            'Congo' => 'CD',
            'Central African Republic' => 'CF',
            'Congo' => 'CG',
            'Switzerland' => 'CH',
            'Côte d\'Ivoire' => 'CI',
            'Cook Islands' => 'CK',
            'Chile' => 'CL',
            'Cameroon' => 'CM',
            'China' => 'CN',
            'Colombia' => 'CO',
            'Costa Rica' => 'CR',
            'Cuba' => 'CU',
            'Cabo Verde' => 'CV',
            'Christmas Island' => 'CX',
            'Cyprus' => 'CY',
            'Czechia' => 'CZ',
            'Germany' => 'DE',
            'Djibouti' => 'DJ',
            'Denmark' => 'DK',
            'Dominica' => 'DM',
            'Dominican Republic' => 'DO',
            'Algeria' => 'DZ',
            'Ecuador' => 'EC',
            'Estonia' => 'EE',
            'Egypt' => 'EG',
            'Western Sahara' => 'EH',
            'Eritrea' => 'ER',
            'Spain' => 'ES',
            'Ethiopia' => 'ET',
            'Finland' => 'FI',
            'Fiji' => 'FJ',
            'Falkland Islands' => 'FK',
            'Micronesia' => 'FM',
            'Faroe Islands' => 'FO',
            'France' => 'FR',
            'Gabon' => 'GA',
            'United Kingdom of Great Britain and Northern Ireland' => 'GB',
            'Grenada' => 'GD',
            'Georgia' => 'GE',
            'French Guiana' => 'GF',
            'Guernsey' => 'GG',
            'Ghana' => 'GH',
            'Gibraltar' => 'GI',
            'Greenland' => 'GL',
            'Gambia' => 'GM',
            'Guinea' => 'GN',
            'Guadeloupe' => 'GP',
            'Equatorial Guinea' => 'GQ',
            'Greece' => 'GR',
            'South Georgia and the South Sandwich Islands' => 'GS',
            'Guatemala' => 'GT',
            'Guam' => 'GU',
            'Guinea-Bissau' => 'GW',
            'Guyana' => 'GY',
            'Hong Kong' => 'HK',
            'Heard Island and McDonald Islands' => 'HM',
            'Honduras' => 'HN',
            'Croatia' => 'HR',
            'Haiti' => 'HT',
            'Hungary' => 'HU',
            'Indonesia' => 'ID',
            'Ireland' => 'IE',
            'Israel' => 'IL',
            'Isle of Man' => 'IM',
            'India' => 'IN',
            'British Indian Ocean Territory' => 'IO',
            'Iraq' => 'IQ',
            'Iran (Islamic Republic of)' => 'IR',
            'Iceland' => 'IS',
            'Italy' => 'IT',
            'Jersey' => 'JE',
            'Jamaica' => 'JM',
            'Jordan' => 'JO',
            'Japan' => 'JP',
            'Kenya' => 'KE',
            'Kyrgyzstan' => 'KG',
            'Cambodia' => 'KH',
            'Kiribati' => 'KI',
            'Comoros' => 'KM',
            'Saint Kitts and Nevis' => 'KN',
            'Korea (the Democratic People\'s Republic of)' => 'KP',
            'Korea (the Republic of)' => 'KR',
            'Kuwait' => 'KW',
            'Cayman Islands' => 'KY',
            'Kazakhstan' => 'KZ',
            'Lao People\'s Democratic Republic' => 'LA',
            'Lebanon' => 'LB',
            'Saint Lucia' => 'LC',
            'Liechtenstein' => 'LI',
            'Sri Lanka' => 'LK',
            'Liberia' => 'LR',
            'Lesotho' => 'LS',
            'Lithuania' => 'LT',
            'Luxembourg' => 'LU',
            'Latvia' => 'LV',
            'Libya' => 'LY',
            'Morocco' => 'MA',
            'Monaco' => 'MC',
            'Moldova (the Republic of)' => 'MD',
            'Montenegro' => 'ME',
            'Madagascar' => 'MG',
            'Marshall Islands' => 'MH',
            'Republic of North Macedonia' => 'MK',
            'Mali' => 'ML',
            'Myanmar' => 'MM',
            'Mongolia' => 'MN',
            'Macao' => 'MO',
            'Northern Mariana Islands' => 'MP',
            'Martinique' => 'MQ',
            'Mauritania' => 'MR',
            'Montserrat' => 'MS',
            'Malta' => 'MT',
            'Mauritius' => 'MU',
            'Maldives' => 'MV',
            'Malawi' => 'MW',
            'Mexico' => 'MX',
            'Malaysia' => 'MY',
            'Mozambique' => 'MZ',
            'Namibia' => 'NA',
            'New Caledonia' => 'NC',
            'Niger' => 'NE',
            'Norfolk Island' => 'NF',
            'Nigeria' => 'NG',
            'Nicaragua' => 'NI',
            'Netherlands' => 'NL',
            'Norway' => 'NO',
            'Nepal' => 'NP',
            'Nauru' => 'NR',
            'Niue' => 'NU',
            'New Zealand' => 'NZ',
            'Oman' => 'OM',
            'Panama' => 'PA',
            'Peru' => 'PE',
            'French Polynesia' => 'PF',
            'Papua New Guinea' => 'PG',
            'Philippines' => 'PH',
            'Pakistan' => 'PK',
            'Poland' => 'PL',
            'Saint Pierre and Miquelon' => 'PM',
            'Pitcairn' => 'PN',
            'Puerto Rico' => 'PR',
            'Palestine, State of' => 'PS',
            'Portugal' => 'PT',
            'Palau' => 'PW',
            'Paraguay' => 'PY',
            'Qatar' => 'QA',
            'Réunion' => 'RE',
            'Romania' => 'RO',
            'Serbia' => 'RS',
            'Russian Federation' => 'RU',
            'Rwanda' => 'RW',
            'Saudi Arabia' => 'SA',
            'Solomon Islands' => 'SB',
            'Seychelles' => 'SC',
            'Sudan' => 'SD',
            'Sweden' => 'SE',
            'Singapore' => 'SG',
            'Saint Helena, Ascension and Tristan da Cunha' => 'SH',
            'Slovenia' => 'SI',
            'Svalbard and Jan Mayen' => 'SJ',
            'Slovakia' => 'SK',
            'Sierra Leone' => 'SL',
            'San Marino' => 'SM',
            'Senegal' => 'SN',
            'Somalia' => 'SO',
            'Suriname' => 'SR',
            'Sao Tome and Principe' => 'ST',
            'El Salvador' => 'SV',
            'Syrian Arab Republic' => 'SY',
            'Eswatini' => 'SZ',
            'Turks and Caicos Islands' => 'TC',
            'Chad' => 'TD',
            'French Southern Territories' => 'TF',
            'Togo' => 'TG',
            'Thailand' => 'TH',
            'Tajikistan' => 'TJ',
            'Tokelau' => 'TK',
            'Timor-Leste' => 'TL',
            'Turkmenistan' => 'TM',
            'Tunisia' => 'TN',
            'Tonga' => 'TO',
            'Turkey' => 'TR',
            'Trinidad and Tobago' => 'TT',
            'Tuvalu' => 'TV',
            'Taiwan' => 'TW',
            'Tanzania, United Republic of' => 'TZ',
            'Ukraine' => 'UA',
            'Uganda' => 'UG',
            'United States Minor Outlying Islands' => 'UM',
            'United States of America' => 'US',
            'Uruguay' => 'UY',
            'Uzbekistan' => 'UZ',
            'Holy See' => 'VA',
            'Saint Vincent and the Grenadines' => 'VC',
            'Venezuela' => 'VE',
            'Virgin Islands (British)' => 'VG',
            'Virgin Islands (U.S.)' => 'VI',
            'Viet Nam' => 'VN',
            'Vanuatu' => 'VU',
            'Wallis and Futuna' => 'WF',
            'Samoa' => 'WS',
            'Yemen' => 'YE',
            'Mayotte' => 'YT',
            'South Africa' => 'ZA',
            'Zambia' => 'ZM',
            'Åland Islands' => 'AX',
        ];

        if (isset($nameToAlpha2[$name])) {
            return $nameToAlpha2[$name];
        }

        return $name;
    }
}
