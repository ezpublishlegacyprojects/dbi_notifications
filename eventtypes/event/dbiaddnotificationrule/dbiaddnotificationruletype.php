<?php

include_once( 'kernel/classes/ezworkflowtype.php' );

class DBIAddNotificationRuleType extends eZWorkflowEventType
{
    function DBIAddNotificationRuleType()
    {
        $this->eZWorkflowEventType( 'dbiaddnotificationrule', ezi18n( 'kernel/workflow/event', 'DBI add notification rule' ) );
        $this->setTriggerTypes( array( 'content' => array( 'publish' => array( 'after' ) ) ) );
    }

    function &attributeDecoder( &$event, $attr )
    {
        $retValue = null;
        switch( $attr )
        {
            case 'selected_attributes':
            {
                $implodedAttributeList = $event->attribute( 'data_text1' );

                $attributeList = array();
                if ( $implodedAttributeList != '' )
                {
                    $attributeList = explode( ';', $implodedAttributeList );
                }
                return $attributeList;
            }

            default:
            {
                eZDebug::writeNotice( 'unknown attribute:' . $attr, 'DBIAddNotificationRuleType' );
            }
        }
        return $retValue;
    }

    function typeFunctionalAttributes()
    {
        return array( 'selected_attributes' );
    }

    function customWorkflowEventHTTPAction( &$http, $action, &$workflowEvent )
    {
        $eventID = $workflowEvent->attribute( 'id' );

        switch ( $action )
        {
            case 'AddAttribute':
            {
                if ( $http->hasPostVariable( 'AttributeSelection_' . $eventID ) )
                {
                    $attributeID = $http->postVariable( 'AttributeSelection_' . $eventID );
                    $workflowEvent->setAttribute( 'data_text1', implode( ';', array_unique( array_merge( $this->attributeDecoder( $workflowEvent, 'selected_attributes' ), array( $attributeID ) ) ) ) );
                }
            } break;

            case 'RemoveAttributes':
            {
                if ( $http->hasPostVariable( 'DeleteAttributeIDArray_' . $eventID ) )
                {
                    $deleteList = $http->postVariable( 'DeleteAttributeIDArray_' . $eventID );
                    $currentList = $this->attributeDecoder( $workflowEvent, 'selected_attributes' );

                    if ( is_array( $deleteList ) )
                    {
                        $dif = array_diff( $currentList, $deleteList );
                        $workflowEvent->setAttribute( 'data_text1', implode( ';', $dif ) );
                    }
                }
            } break;

            default:
            {
                eZDebug::writeNotice( 'unknown custom action: ' . $action, 'DBIAddNotificationRuleType' );
            }
        }
    }

    /*!
     \reimp
    */
    function execute( &$process, &$event )
    {
        $parameters = $process->attribute( 'parameter_list' );
        include_once( 'kernel/classes/ezcontentobject.php' );
        $object =& eZContentObject::fetch( $parameters['object_id'] );

        $datamap = $object->attribute( 'data_map' );
        $attributeIDList = $event->attribute( 'selected_attributes' );

        $mainNodeID = $object->attribute( 'main_node_id' );

        foreach ( $datamap as $attribute )
        {
            if ( in_array( $attribute->attribute('contentclassattribute_id'), $attributeIDList ) )
            {
                eZDebug::writeDebug( 'found matching attribute: ' . $attribute->attribute('contentclassattribute_id'), 'DBIAddNotificationRuleType' );

                $dataTypeString = $attribute->attribute( 'data_type_string' );
                if ( $dataTypeString != 'ezselection' )
                {
                    $attributeName = $attribute->attribute( 'contentclass_attribute_name' );
                    eZDebug::writeError( "attribute '$attributeName' has datatype '$dataTypeString', expected: ezselection", 'DBIAddNotificationRuleType::execute' );
                    continue;
                }

                $selectedOptions = $attribute->attribute( 'content' );
                $classContent = $attribute->attribute( 'class_content' );
                $possibleOptions = $classContent['options'];
                foreach ( $possibleOptions as $possibleOption )
                {
                    if ( in_array( $possibleOption['id'], $selectedOptions ) )
                    {
                        DBIAddNotificationRuleType::createNotificationRulesByOptionName( $object->attribute( 'id' ), $possibleOption['name'] );
                    }
                }
            }
        }

        return EZ_WORKFLOW_TYPE_STATUS_ACCEPTED;
    }

    /*!
     \static
    */
    function createNotificationRulesByOptionName( $userID, $optionName )
    {
        $ini =& eZINI::instance( 'dbi_notifications.ini' );
        $nodeIDList = $ini->hasGroup( $optionName ) && $ini->hasVariable( $optionName, 'SubtreeNotifications' ) ? $ini->variable( $optionName, 'SubtreeNotifications' ) : array();

        if ( is_array( $nodeIDList ) && count( $nodeIDList ) > 0 )
        {
            foreach ( $nodeIDList as $nodeID )
            {
                DBIAddNotificationRuleType::createNotificationRuleIfNeeded( $userID, $nodeID );
            }
        }
        else
        {
            eZDebug::writeWarning( "no notifications set in dbi_notification.ini for option $optionName", 'DBIAddNotificationRuleType::execute' );
        }
    }

    /*!
     \static
    */
    function createNotificationRuleIfNeeded( $userID, $nodeID )
    {
        include_once( 'kernel/classes/notification/handler/ezsubtree/ezsubtreenotificationrule.php' );

        $nodeIDList =& eZSubtreeNotificationRule::fetchNodesForUserID( $userID, false );

        if ( !in_array( $nodeID, $nodeIDList ) )
        {
            $rule =& eZSubtreeNotificationRule::create( $nodeID, $userID );
            $rule->store();
        }
    }
}

eZWorkflowEventType::registerType( 'dbiaddnotificationrule', 'dbiaddnotificationruletype' );

?>
