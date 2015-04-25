<?php
	require('server/exceptions/class.BusException.php');

	/**
	* Generic event bus with subscribe/notify architecture
	*
	* Every notifier registers itselfs by this class so that it can receive events. The events represent changes in the underlying
	* MAPI store like changes to folders, e-mails, calendar items, etc.
	*
	* A notifier can register itself for the following events:
	* - OBJECT_SAVE
	* - OBJECT_DELETE
	*
	* - TABLE_SAVE
	* - TABLE_DELETE
	*
	* - REQUEST_START
	* - REQUEST_END
	*
	* To use the REQUEST_* events, make sure you register your notifier with REQUEST_ENTRYID 
	* as the dummy entryid.
	*
	* Events:
	*
	* (The parameters that are discussed are passed to notify() and sent to the update() methods of the notifiers)
	*
	* <b>OBJECT_SAVE</b>
	* This event is triggered when a folder object is created or modified. The entryid parameter is the entryid
	* of the object that has been created or modified. The data parameter contains an array of properties of the
	* object, with the following properties: PR_ENTRYID, PR_STORE_ENTRYID
	*
	* <b>OBJECT_DELETE</b>
	* This event is triggered when a folder is deleted. Teh entryid parameter is the entryid of the object
	* that has been deleted. The data parameter contains an array of properties of the deleted object, with
	* the following properties: PR_ENTRYID, PR_STORE_ENTRYID
	*
	* <b>TABLE_SAVE</b>
	* This event is triggered when a message item is created or modified. The entryid parameter is the entryid of the modified
	* object. The data parameter contains the following properties of the modified message: PR_ENTRYID, PR_PARENT_ENTRYID, 
	* PR_STORE_ENTRYID
	*
	* <b>TABLE_DELETE</b>
	* This event is triggered when a message item is deleted. The entryid parameter is the entryid of the deleted object. The
	* data parameter contains the following properties of the deleted message: PR_ENTRYID, PR_PARENT_ENTRYID, PR_STORE_ENTRYID
	*
	* <b>REQUEST_START</b>
	* This event is triggered for EVERY XML request that is done to the backend, before processing of the XML or other events.
	* The entryid parameter must be the fixed value REQUEST_ENTRYID. The $data parameter is not passed.
	*
	* <b>REQUEST_END</b>
	* This event is triggereed for EVERY XML request that is done to the backend, after all notifiers processing and will be the last
	* event triggered. The entryid parameter must be that fixed value REQUEST_ENTRYID. The $data parameter is not passed.
	*
	* @todo The event names are rather misleading and should be renamed to FOLDER_CHANGE, FOLDER_DELETE, MESSAGE_CHANGE, MESSAGE_DELETE
	* @todo The entryid is passed in $entryid AND in $data in almost all cases, which is rather wasteful
	*
	* @package core
	*/
	class Bus
	{
		/**
		 * @var array data which is sent back to the client.
		 */
		var $responseData;

		/**
		 * @var array all registered notifiers.
		 */
		var $registeredNotifiers;

		/**
		 * @var array all registered store notifiers.
		 */
		var $registeredStoreNotifiers;

		/**
		 * @var array all notifier objects
		 */
		var $notifiers;

		/**
		 * Constructor
		 */
		function Bus()
		{
			$this->responseData = array();

			$this->registeredNotifiers = array();
			$this->registeredStoreNotifiers = array();
			$this->notifiers = array();
		} 

		/**
		 * Register a notifier on the bus.
		 *
		 * This will check if a instance for the given $notifierName already exists, and if
		 * not will instantiate it through the dispatcher. It will then use $this->registerEvent
		 * to register the instance for all correct events on the given $entryid(s)
		 *
		 * After a notifier is registered on the bus, it will receive updates to objects generated by the modules
		 * which are handling the requests. For example a notifier is registered on the inbox can receive changes
		 * of the item count of the inbox by specifying the entryid of the inbox here and the event
		 *
		 * @access public
		 * @param String $notifierName The classname of the notifier to register
		 * @param Array/Binary $entryid The entryid or entryids on which the notifier should
		 * be registered
		 * @param Boolean $store optional If true, the $entryid points to the store and all events within the store are
		 * bubbled to this notifier object.
		 */
		function registerNotifier($notifierName, $entryid, $store = false)
		{
			if (!isset($this->notifiers[$notifierName])) {
				$this->notifiers[$notifierName] = $GLOBALS["dispatcher"]->loadNotifier($notifierName);
			}

			// Obtain the events which are supported by this notifier
			$events = $this->notifiers[$notifierName]->getEvents();

			if (is_array($entryid)) {
				foreach($entryid as $id) {
					$this->registerEvent($notifierName, $id, $events, $store);
				}
			} else {
				$this->registerEvent($notifierName, $entryid, $events, $store);
			}
		}

		/**
		 * Register notifier events on the bus.
		 * 
		 * Called by $registerNotifier after the notifier instance was created. This function will then
		 * register the given $notifierName to the provided $entryid and will mark the $events for which
		 * this notifier should be fired.
		 *
		 * Valid values for $events are OBJECT_SAVE, OBJECT_DELETE, TABLE_SAVE, TABLE_DELETE, REQUEST_START and REQUEST_END
		 *
		 * @access private
		 * @param string $notifierName The classname of the notifier to register
		 * @param string $entryid EntryID of an object coupled to the notifier
		 * @param number $events Bitmask of events that the notifier should receive
		 * @param boolean $store optional If true, the $entryid points to the store and all events within the store are
		 * bubbled to this notifier object.
		 */
		function registerEvent($notifierName, $entryid, $events, $store = false)
		{
			$entryidCmp = $GLOBALS["entryid"];

			if ($store) {
				$found = false;

				foreach ($this->registeredStoreNotifiers as $key => &$storeNotifier) {
					if ($entryidCmp->compareStoreEntryIds($storeNotifier['entryid'], $entryid)) {
						$storeNotifier[$notifierName] = Array( 'events' => $events);
						$found = true;
						break;
					}
				}
				unset($storeNotifier);

				if (!$found) {
					$this->registeredStoreNotifiers[] = Array(
						'entryid' => $entryid,
						$notifierName => Array( 'events' => $events)
					);
				}
			} else {
				$found = false;

				foreach ($this->registeredNotifiers as $key => &$folderNotifier) {
					if ($entryidCmp->compareEntryIds($folderNotifier['entryid'], $entryid)) {
						$folderNotifier[$notifierName] = Array( 'events' => $events);
						$found = true;
						break;
					}
				}
				unset($folderNotifier);

				if (!$found) {
					$this->registeredNotifiers[] = Array(
						'entryid' => $entryid,
						$notifierName => Array( 'events' => $events)
					);
				}
			}
		} 
		
		/**
		 * Broadcast an update to notifiers attached to bus.
		 *
		 * This function is used to send an update to other notifiers that have been attached to the bus via register().
		 *
		 * @access public
		 * @param string $entryid Entryid of the object for which the event has happened
		 * @param int $event Event which should be fired. Can be any of OBJECT_SAVE, OBJECT_DELETE, TABLE_SAVE, TABLE_DELETE, REQUEST_START and REQUEST_END.
		 * @param array $data The data which is used to execute the event, which differs for each event type.
		 */
		function notify($entryID, $event, $data=null)
		{
			$entryidCmp = $GLOBALS["entryid"];

			// Notifiers must be get only ONE update
			$updatedNotifiers = array();

			// Check if there are bubbled notifiers, and if a PR_STORE_ENTRYID was provided
			// on which the notifiers should be executed.
			if ($entryID !== REQUEST_ENTRYID && $data && isset($data[PR_STORE_ENTRYID])) {
				$storeEntryid = bin2hex($data[PR_STORE_ENTRYID]);

				// Update the store notifier
				foreach ($this->registeredStoreNotifiers as $key => &$storeNotifier) {
					if ($entryidCmp->compareStoreEntryIds($storeNotifier['entryid'], $storeEntryid)) {
						foreach ($storeNotifier as $key => $notifier) {
							if ($notifier['events'] & $event) {
								if (!isset($updatedNotifiers[$key])) {
									if (isset($this->notifiers[$key]) && is_object($this->notifiers[$key])) {
										$this->notifiers[$key]->update($event, $entryID, $data);
										$updatedNotifiers[$key] = true;
									}
								}
							}
						}
						break;
					}
				}
				unset($storeNotifier);
			}

			// Update the notifier
			foreach ($this->registeredNotifiers as $key => &$folderNotifier) {
				if (($entryID === REQUEST_ENTRYID && $folderNotifier['entryid'] === REQUEST_ENTRYID) ||
				    ($entryidCmp->compareEntryIds($folderNotifier['entryid'], $entryID))) {
					foreach ($folderNotifier as $key => $notifier) {
						if (isset($notifier['events']) && ($notifier['events'] & $event)) {
							if (!isset($updatedNotifiers[$key])) {
								if (isset($this->notifiers[$key]) && is_object($this->notifiers[$key])) {
									$this->notifiers[$key]->update($event, $entryID, $data);
									$updatedNotifiers[$key] = true;
								}
							}
						}
					}
					break;
				}
			}
			unset($folderNotifier);
		}
		
		/**
		 * Add reponse data to collected XML response
		 *
		 * This function is called by all modules when they want to output some XML response data
		 * to the client. It simply copies the XML response data to an internal structure. The data
		 * is later collected via getData() when all modules are done processing, after which the
		 * data is sent to the client.
		 *
		 * The data added is not checked for validity, and is therefore completely free-form. However,
		 * 
		 * @access public
		 * @param array $data data to be added.
		 */
		function addData($data)
		{
			foreach($data as $moduleName => $module) {
				if(isset($this->responseData[$moduleName])) {
					foreach($module as $moduleId => $action) {
						if(isset($this->responseData[$moduleName][$moduleId])) {
							foreach($action as $actionType => $actionData) {
								if(isset($this->responseData[$moduleName][$moduleId][$actionType])) {
									if($this->responseData[$moduleName][$moduleId][$actionType] == $actionData) {
										// in future we can think something about this but for now we throw exception
										throw new BusException(_("Can't add data to bus, same action type is present in bus"));
									}
								}
							}
						}
					}
				}
			}

			$this->responseData = array_merge_recursive($this->responseData, $data);
		}

		/**
		 * Function which returns the data stored via addData()
		 * @access public
		 * @return array Response data.
		 */
		function getData()
		{
			if(empty($this->responseData)) {
				// we shouldn't send empty responses
				throw new BusException(_("Response data requested from bus but it doesn't have any data."));
			} else {
				return $this->responseData;
			}
		}

		/**
		 * Reset the bus state and response data, and send resets to all notifier.
		 *
		 * Since the bus is a global, persistent object, the reset() function is called before or after
		 * processing. This makes sure that the on-disk serialized representation of the bus object is as
		 * small as possible.
		 * @access public
		 */
		function reset()
		{
			$this->responseData = array();

			foreach($this->notifiers as $key => $notifier) {
				$this->notifiers[$key]->reset();
			}
		}
	} 	
?>
