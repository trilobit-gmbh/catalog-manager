<?php

namespace CatalogManager;

class FrontendEditing extends CatalogController {


    public $strAct;
    public $strItemID;
    public $strPageID;
    public $strRedirectID;
    public $arrOptions = [];
    public $strTemplate = '';
    
    private $objTemplate;
    private $arrValues = [];
    private $arrCatalog = [];
    private $arrPalettes = [];
    private $arrFormFields = [];
    private $strSubmitName = '';
    private $blnNoSubmit = false;
    private $blnHasUpload = false;
    private $strTemporaryPalette = 'general_legend';


    public function __construct() {

        parent::__construct();

        $this->import( 'SQLQueryHelper' );
        $this->import( 'SQLQueryBuilder' );
        $this->import( 'DCABuilderHelper' );
        $this->import( 'I18nCatalogTranslator' );
    }


    public function initialize() {

        global $objPage;

        \System::loadLanguageFile('catalog_manager');

        $this->setOptions();

        if ( $this->strItemID && $this->strAct && in_array( $this->strAct, [ 'copy', 'edit' ] ) ) {

            $this->setValues();
        }

        $this->strSubmitName = 'catalog_' . $this->catalogTablename;
        $this->arrCatalog = $this->SQLQueryHelper->getCatalogByTablename( $this->catalogTablename );
        $this->arrFormFields = $this->SQLQueryHelper->getCatalogFieldsByCatalogID( $this->arrCatalog['id'], function ( $arrField, $strFieldname ) {

            if ( $arrField['type'] == 'fieldsetStart' ) {

                $this->strTemporaryPalette = $this->I18nCatalogTranslator->getLegendLabel( $arrField['title'], $arrField['label'] );
            }

            if ( !$this->DCABuilderHelper->isValidField( $arrField ) ) return null;


            $arrDCField = $this->DCABuilderHelper->convertCatalogField2DCA( $arrField );

            if ( $arrField['type'] == 'hidden' ) {

                $arrDCField['inputType'] = 'hidden';
            }

            $arrDCField['_fieldname'] = $strFieldname;
            $arrDCField['_palette'] = $this->I18nCatalogTranslator->getLegendLabel( $this->strTemporaryPalette );

            return $arrDCField;
        });

        $this->arrCatalog['operations'] = Toolkit::deserialize( $this->arrCatalog['operations'] );
        $arrPredefinedDCFields = $this->DCABuilderHelper->getPredefinedDCFields();

        if ( in_array( 'invisible', $this->arrCatalog['operations'] ) ) {

            $this->arrFormFields[] = $arrPredefinedDCFields['invisible'];
        }

        unset( $arrPredefinedDCFields['invisible'] );

        array_insert( $this->arrFormFields, 0, $arrPredefinedDCFields );

        if ( $this->catalogFormRedirect && $this->catalogFormRedirect !== '0' ) {

            $this->strRedirectID = $this->catalogFormRedirect;
        }

        else {

            $this->strRedirectID = $objPage->id;
        }

        $this->strPageID = $objPage->id;
    }


    public function checkPermission() {

        $this->import( 'FrontendEditingPermission' );

        $this->FrontendEditingPermission->blnDisablePermissions = $this->catalogEnableFrontendPermission ? false : true;
        $this->FrontendEditingPermission->initialize();

        return $this->FrontendEditingPermission->hasAccess( $this->catalogTablename );
    }


    public function getCatalogForm() {

        $intIndex = 0;
        $this->objTemplate = new \FrontendTemplate( $this->strTemplate );
        $this->objTemplate->setData( $this->arrOptions );

        if ( !empty( $this->arrFormFields ) && is_array( $this->arrFormFields ) ) {

            foreach ( $this->arrFormFields as $arrField ) {

                if ( !$arrField ) continue;

                $this->generateForm( $arrField, $intIndex );
                $intIndex++;
            }
        }

        if ( !$this->blnNoSubmit && \Input::post('FORM_SUBMIT') == $this->strSubmitName ) {

            $this->saveEntity();
        }

        if ( !$this->disableCaptcha ) {

            $objCaptcha = $this->getCaptcha();
            $objCaptcha->rowClass = 'row_' . $intIndex . ( ( $intIndex == 0 ) ? ' row_first' : '' ) . ( ( ( $intIndex % 2 ) == 0 ) ? ' even' : ' odd' );

            $this->objTemplate->fields .= $objCaptcha->parse();
        }

        $this->objTemplate->method = 'POST';
        $this->objTemplate->formId = $this->strSubmitName;
        $this->objTemplate->categories = $this->arrPalettes;
        $this->objTemplate->submitName = $this->strSubmitName;
        $this->objTemplate->action = \Environment::get( 'indexFreeRequest' );
        $this->objTemplate->attributes = $this->catalogNoValidate ? 'novalidate' : '';
        $this->objTemplate->enctype = $this->blnHasUpload ? 'multipart/form-data' : 'application/x-www-form-urlencoded';

        return $this->objTemplate->parse();
    }


    private function generateForm( $arrField, $intIndex ) {

        $arrField = $this->convertWidgetToField( $arrField );
        $strClass = $this->fieldClassExist( $arrField['inputType'] );

        if ( $strClass == false ) return null;

        $objWidget = new $strClass( $strClass::getAttributesFromDca( $arrField, $arrField['_fieldname'], $arrField['default'], '', '' ) );

        $objWidget->storeValues = true;
        $objWidget->id = 'id_' . $arrField['_fieldname'];
        $objWidget->value = $this->arrValues[ $arrField['_fieldname'] ];
        $objWidget->rowClass = 'row_' . $intIndex . ( ( $intIndex == 0 ) ? ' row_first' : '' ) . ( ( ( $intIndex % 2 ) == 0 ) ? ' even' : ' odd' );
        
        if ( $this->strAct == 'copy' && $arrField['eval']['doNotCopy'] === true ) {

            $objWidget->value = '';
        }

        if ( $arrField['inputType'] == 'upload' ) {

            $objWidget->storeFile = $this->catalogStoreFile;
            $objWidget->useHomeDir = $this->catalogUseHomeDir;
            $objWidget->uploadFolder = $this->catalogUploadFolder;
            $objWidget->doNotOverwrite = $this->catalogDoNotOverwrite;
            $objWidget->extensions = $arrField['eval']['extensions'];
            $objWidget->maxlength = $arrField['eval']['maxsize'];

            $this->blnHasUpload = true;
        }

        if ( $arrField['eval']['rgxp'] && in_array( $arrField['eval']['rgxp'], [ 'date', 'time', 'datim' ] ) ) {

            $strDateFormat = \Date::getFormatFromRgxp( $arrField['eval']['rgxp'] );
            $objWidget->value = \Date::parse( $strDateFormat, $objWidget->value );
        }

        if ( $objWidget instanceof \FormPassword ) {

            $objWidget->rowClassConfirm = 'row_' . ++$intIndex . ( ( ( $intIndex % 2 ) == 0 ) ? ' even' : ' odd' );
        }

        if ( \Input::post('FORM_SUBMIT') == $this->strSubmitName ) {

            $objWidget->validate();
            $varValue = $objWidget->value;

            if ( $varValue && is_string( $varValue ) ) {

                $varValue = $this->decodeValue( $varValue );
                $varValue = $this->replaceInsertTags( $varValue );
            }

            if ( $varValue != '' && in_array( $arrField, [ 'date', 'time', 'datim' ] ) ) {

                try {

                    $objDate = new \Date( $varValue, \Date::getFormatFromRgxp( $arrField['eval']['rgxp'] ) );
                    $varValue = $objDate->tstamp;

                } catch ( \OutOfBoundsException $objError ) {

                    $objWidget->addError( sprintf( $GLOBALS['TL_LANG']['ERR']['invalidDate'], $varValue ) );
                }
            }

            if ( $arrField['eval']['unique'] && $varValue != '' && !$this->SQLQueryHelper->SQLQueryBuilder->Database->isUniqueValue( $this->catalogTablename, $arrField['_fieldname'], $varValue, ( $this->strAct == 'edit' ? $this->strItemID : null ) ) ) {

                $objWidget->addError( sprintf($GLOBALS['TL_LANG']['ERR']['unique'], $arrField['label'][0] ?: $arrField['_fieldname'] ) );
            }

            if ( $objWidget->submitInput() && !$objWidget->hasErrors() && is_array( $arrField['save_callback'] ) ) {

                foreach ( $arrField['save_callback'] as $arrCallback ) {

                    try {

                        if ( is_array( $arrCallback ) ) {

                            $this->import( $arrCallback[0] );
                            $varValue = $this->{$arrCallback[0]}->{$arrCallback[1]}( $varValue, null );
                        }

                        elseif( is_callable( $arrCallback ) ) {

                            $varValue = $arrCallback( $varValue, null );
                        }
                    }

                    catch  (\Exception $objError ) {

                        $objWidget->class = 'error';
                        $objWidget->addError( $objError->getMessage() );
                    }
                }
            }

            if ( $objWidget->hasErrors() ) {

                $this->blnNoSubmit = true;
            }

            elseif( $objWidget->submitInput() ) {

                if ( $varValue === '' ) {

                    $varValue = $objWidget->getEmptyValue();
                }

                if ( $arrField['eval']['encrypt'] ) {

                    $varValue = \Encryption::encrypt( $varValue );
                }

                $this->arrValues[ $arrField['_fieldname'] ] = $varValue;
            }

            $arrFiles = $_SESSION['FILES'];

            if ( !empty( $arrFiles ) && isset( $arrFiles[ $arrField['_fieldname'] ] ) && $this->catalogStoreFile ) {

                $strRoot = TL_ROOT . '/';
                $strUuid = $arrFiles[ $arrField['_fieldname'] ]['uuid'];
                $strFile = substr( $arrFiles[ $arrField['_fieldname'] ]['tmp_name'], strlen( $strRoot ) );
                $objFiles = \FilesModel::findByPath( $strFile );

                if ( $objFiles !== null ) {

                    $strUuid = $objFiles->uuid;
                }

                $this->arrValues[ $arrField['_fieldname'] ] = $strUuid;
            }

            if ( !empty( $arrFiles ) && isset( $arrFiles[ $arrField['_fieldname'] ] ) ) {

                unset( $_SESSION['FILES'][ $arrField['_fieldname'] ] );
            }
        }

        $strWidget = $objWidget->parse();
        $this->objTemplate->fields .= $strWidget;
        $this->arrPalettes[ $arrField['_palette'] ][ $arrField['_fieldname'] ] = $strWidget;
    }


    public function deleteEntity() {

        $this->import( 'SQLBuilder' );

        if (  $this->SQLBuilder->Database->tableExists( $this->catalogTablename ) ) {

            $this->SQLBuilder->Database->prepare( sprintf( 'DELETE FROM %s WHERE id = ? ', $this->catalogTablename ) )->execute( $this->strItemID );
        }

        $this->redirectToFrontendPage( $this->strRedirectID );
    }


    private function saveEntity() {

        $this->import( 'SQLBuilder' );

        if ( $this->arrCatalog['useGeoCoordinates'] ) {

            $arrCords = [];
            $objGeoCoding = new GeoCoding();
            $strGeoInputType = $this->arrCatalog['addressInputType'];

            switch ( $strGeoInputType ) {

                case 'useSingleField':

                    $arrCords = $objGeoCoding->getCords( $this->arrValues[ $this->arrCatalog['geoAddress'] ], 'en', true );

                    break;

                case 'useMultipleFields':

                    $objGeoCoding->setCity( $this->arrValues[ $this->arrCatalog['geoCity'] ] );
                    $objGeoCoding->setStreet( $this->arrValues[ $this->arrCatalog['geoCity'] ] );
                    $objGeoCoding->setPostal( $this->arrValues[ $this->arrCatalog['geoPostal'] ] );
                    $objGeoCoding->setCountry( $this->arrValues[ $this->arrCatalog['geoCountry'] ] );
                    $objGeoCoding->setStreetNumber( $this->arrValues[ $this->arrCatalog['geoStreetNumber'] ] );

                    $arrCords = $objGeoCoding->getCords( '', 'en', true );

                    break;
            }

            if ( ( $arrCords['lat'] || $arrCords['lng'] ) && ( $this->arrCatalog['lngField'] && $this->arrCatalog['latField'] ) ) {

                $this->arrValues[ $this->arrCatalog['lngField'] ] = $arrCords['lng'];
                $this->arrValues[ $this->arrCatalog['latField'] ] = $arrCords['lat'];
            }
        }

        $this->arrValues = Toolkit::prepareValues4Db( $this->arrValues );

        switch ( $this->strAct ) {

            case 'create':

                if ( $this->arrCatalog['pTable'] ) {

                    if ( !\Input::get('pid') ) return null;

                    $this->arrValues['pid'] = \Input::get('pid');
                }

                if ( $this->SQLBuilder->Database->fieldExists( 'sorting', $this->catalogTablename ) ) {

                    $intSort = $this->SQLBuilder->Database->prepare( sprintf( 'SELECT MAX(sorting) FROM %s;', $this->catalogTablename ) )->execute()->row( 'MAX(sorting)' )[0];
                    $this->arrValues['sorting'] = intval( $intSort ) + 100;
                }

                if ( $this->SQLBuilder->Database->fieldExists( 'tstamp', $this->catalogTablename ) ) {

                    $this->arrValues['tstamp'] = \Date::floorToMinute();
                }

                $this->SQLBuilder->Database->prepare( 'INSERT INTO '. $this->catalogTablename .' %s' )->set( $this->arrValues )->execute();
                $this->redirectToFrontendPage( $this->strRedirectID );

                break;

            case 'edit':

                $this->SQLBuilder->Database->prepare( 'UPDATE '. $this->catalogTablename .' %s WHERE id = ?' )->set( $this->arrValues )->execute( $this->strItemID );

                break;

            case 'copy':

                unset( $this->arrValues['id'] );

                $this->SQLBuilder->Database->prepare( 'INSERT INTO '. $this->catalogTablename .' %s' )->set( $this->arrValues )->execute();

                break;
        }
    }


    private function setValues() {

        if ( $this->strItemID && $this->catalogTablename ) {
            
            $this->arrValues = $this->SQLQueryHelper->getCatalogTableItemByID( $this->catalogTablename, $this->strItemID );
        }

        if ( !empty( $this->arrValues ) && is_array( $this->arrValues ) ) {

            foreach ( $this->arrValues as $strKey => $varValue ) {

                $this->arrValues[$strKey] = \Input::post( $strKey ) !== null ? \Input::post( $strKey ) : $varValue;
            }
        }
    }


    private function setOptions() {

        if ( !empty( $this->arrOptions ) && is_array( $this->arrOptions ) ) {

            foreach ( $this->arrOptions as $strKey => $varValue ) {

                $this->{$strKey} = $varValue;
            }
        }
    }


    private function decodeValue( $varValue ) {

        if ( class_exists('StringUtil') ) {

            $varValue = \StringUtil::decodeEntities( $varValue );
        }

        else {

            $varValue = \Input::decodeEntities( $varValue );
        }

        return $varValue;
    }


    private function getCaptcha() {

        $arrCaptcha = [

            'label' => $GLOBALS['TL_LANG']['MSC']['securityQuestion'],
            'id' => 'id_',
            'type' => 'captcha',
            'mandatory' => true,
            'required' => true,
            'tableless' => $this->tableless
        ];

        $strClass = $GLOBALS['TL_FFL']['captcha'];

        if ( !class_exists( $strClass ) ) {

            $strClass = 'FormCaptcha';
        }

        $objCaptcha = new $strClass( $arrCaptcha );

        if ( \Input::post('FORM_SUBMIT') == $this->strSubmitName ) {

            $objCaptcha->validate();

            if ( $objCaptcha->hasErrors() ) {

                $this->blnNoSubmit = true;
            }
        }

        return $objCaptcha;
    }


    private function convertWidgetToField( $arrField ) {

        if ( $arrField['inputType'] == 'checkboxWizard' ) {

            $arrField['inputType'] = 'checkbox';
        }

        if ( $arrField['inputType'] == 'fileTree' ) {

            $arrField['inputType'] = 'upload';
        }

        if ( $arrField['inputType'] == 'catalogMessageWidget' ) {

            $arrField['inputType'] = 'catalogMessageForm';
        }

        $arrField['eval']['tableless'] = $this->tableless;
        $arrField['eval']['required'] = $arrField['eval']['mandatory'];

        return $arrField;
    }


    private function fieldClassExist( $strInputType ) {

        $strClass = $GLOBALS['TL_FFL'][$strInputType];

        if ( !class_exists( $strClass ) ) {

            return false;
        }

        return $strClass;
    }
}