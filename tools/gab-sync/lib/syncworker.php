<?php
/***********************************************
* File      :   synchworker.php
* Project   :   Z-Push - tools - GAB sync
* Descr     :   Main synchronization class.
*
* Created   :   28.01.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
* ************************************************/

include_once("gabentry.php");

abstract class SyncWorker {
    protected $chunkType;
    private $hashFieldId;

    /**
     * The constructor should do all required login actions.
     */
    public function __construct() {
        $this->chunkType = @constant("HASHFIELD") . "-" . @constant("AMOUNT_OF_CHUNKS");
        $this->hashFieldId = @constant("HASHFIELD");
    }

    /**
     * Simulates the synchronization, showing statistics but without touching any data.
     *
     * @access public
     * @return void
     */
    public function Simulate() {
        $this->Log("Simulating the synchronization. NO DATA IS GOING TO BE WRITTEN.".PHP_EOL);
        $this->Sync(false);
    }

    /**
     * Performs the synchronization.
     *  - tries to get the hidden folder id (creates it if not available)
     *  - clears all messages from the folder that do not exist
     *  - gets all GAB entries
     *  - sorts them into chunks
     *  - serializes the chunk
     *  - sends it to SetChunkData() to be written
     *  - shows some stats
     *
     * @param string $doWrite   if set to false, no data will be written (simulated mode). Default: true.
     * @access public
     * @return void
     */
    public function Sync($doWrite = true) {
        // get the folderid of the hidden folder - will be created if not yet available
        $folderid = $this->getFolderId($doWrite);

        $this->Log(sprintf("Starting %sGAB sync to store '%s' on id '%s'", (!$doWrite?"simulated ":""), HIDDEN_FOLDERSTORE, $folderid));

        // remove all messages that do not match the current $chunkType
        if ($doWrite)
            $this->ClearAllNotCurrentChunkType($folderid);

        // get all GAB entries
        $gab = $this->GetGAB();

        // build the chunks
        $chunks = array();
        foreach ($gab as $entry) {
            $key = $this->getHashFieldValue($entry);
            // Ignore entries without the configured hash value. Warning is logged by getHashFieldValue().
            if (!$key)
                continue;

            $id = $this->calculateChunkId($key);
            if (!isset($chunks[$id])) {
                $chunks[$id] = array();
            }
            $chunks[$id][$key] = $entry;
        }

        $entries = 0;
        $minEntries = $minSize = 99999999999;
        $maxEntries = $maxSize = 0;
        $bytes = 0;
        foreach($chunks as $chunkId => $chunk) {
            // get the hash, sort the chunk and serialize it
            $amountEntries = count($chunk);
            // sort by keys
            ksort($chunk);
            $chunkData = json_encode($chunk);

            // some stats
            $entries += $amountEntries;
            if ($minEntries > $amountEntries)
                $minEntries = $amountEntries;
            if ($maxEntries < $amountEntries)
                $maxEntries = $amountEntries;
            $size = strlen($chunkData);
            $bytes += $size;
            if ($minSize > $size)
                $minSize = $size;
            if ($maxSize < $size)
                $maxSize = $size;

            // save/update the chunk data
            if ($doWrite) {
                $chunkName = $this->chunkType . "/". $chunkId;
                $chunkCRC = md5($chunkData);
                $this->SetChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC);
            }
        }

        // Calc the ideal amount of chunks (round up to 5)
        //   by size: we want to have chunks with arount 500 KB of data in it
        //   by entries: max 10 entries per chunk
        $idealBySize = ceil($bytes/500000/ 5) * 5;
        $idealByEntries = ceil((count($gab)/10) / 5) * 5;

        $l  = sprintf("\nSync:\n\tItems in GAB:\t\t\t%d\n\tTotal data size: \t\t%d B\n\n", count($gab), $bytes);
        $l .= sprintf("\tAvg. of items per chunk: \t%g\n\tMin. of items per chunk: \t%d\n\tMax. of items per chunk: \t%d\n\n", ($entries/count($chunks)), $minEntries, $maxEntries);
        $l .= sprintf("\tAvg. of size per chunk: \t%d B\n\tMin. of size per chunk: \t%d B\n\tMax. of size per chunk: \t%d B\n\n", ($bytes/count($chunks)), $minSize, $maxSize);
        $l .= sprintf("\tConfigured amout of chunks:\t%d\n\tIdeal amount by entries: \t%d\n\tIdeal amount by size: \t\t%d", AMOUNT_OF_CHUNKS, $idealByEntries, $idealBySize);
        $this->Log($l);
    }

    /**
     * Updates a single entry of the GAB in the respective chunk.
     *
     * @param string $uniqueId
     *
     * @access public
     * @return void
     */
    public function SyncOne($uniqueId) {
        $this->Log(sprintf("Sync-one: %s = '%s'", UNIQUEID, $uniqueId));

        // search for the entry in the GAB
        $entries = $this->GetGAB($uniqueId);

        // if an entry is found, update the chunk
        // if the entry is NOT found, we should remove it from the chunk (entry deleted)
        if (isset($entries[0])) {
            $entry = $entries[0];
            $key = $this->getHashFieldValue($entry);
            if (!$key) {
                $this->Terminate("SyncOne: Unique key can't be found in entry from GAB. Aborting.");
            }
        }
        else {
            $entry = false;
            $key = $uniqueId;
        }

        // get the data for the chunkId
        $folderid = $this->getFolderId();
        $chunkId = $this->calculateChunkId($key);
        $chunkName = $this->chunkType . "/". $chunkId;
        $chunkdata = $this->GetChunkData($folderid, $chunkName);
        $chunk = json_decode($chunkdata, true);

        // update or remove the entry
        if ($entry) {
            $chunk[$key] = $entry;
            $this->Log("Updating entry.");
        }
        else {
            // if we have the key in the chunk, it existed before and should be deleted
            if (isset($chunk[$key])) {
                unset($chunk[$key]);
                $this->Log("Deleting entry.");
            }
            // if we get here, the entry was not found in the GAB but also not in the chunk data. Invalid entry, abort!
            else {
                $this->Terminate(sprintf("No entry for '%s' can be found in GAB or hashed entries. Nothing to do here. Aborting.", $uniqueId));
            }
        }

        // get the hash, sort the chunk and serialize it
        $amountEntries = count($chunk);
        // sort by keys
        ksort($chunk);
        $chunkData = json_encode($chunk);

        // update the chunk data
        $chunkCRC = md5($chunkData);
        $status = $this->SetChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC);
        if ($status) {
            $this->Log("Success!");
        }
    }

    /**
     * Clears all data from the hidden folder (without removing it).
     * This will cause a serverside clearing of all user gabs.
     *
     * @access public
     * @return void
     */
    public function ClearAll() {
        $folderid = $this->GetHiddenFolderId();
        if (!$folderid) {
            $this->Terminate("Could not locate folder. Aborting.");
        }

        $status = $this->ClearFolderContents($folderid);
        if ($status) {
            $this->Log("Success!");
        }
    }

    /**
     * Clears all data from the hidden folder and removes it.
     * This will cause a serverside clearing of all user gabs and a synchronization stop.
     *
     * @access public
     * @return void
     */
    public function DeleteAll() {
        $folderid = $this->GetHiddenFolderId();
        if (!$folderid) {
            $this->Terminate("Could not locate folder. Aborting.");
        }
        $emptystatus = $this->ClearFolderContents($folderid);
        if ($emptystatus) {
            $status = $this->DeleteHiddenFolder($folderid);
            if ($status) {
                $this->Log("Success!");
            }
        }
    }

    /**
     * Logs a message to the command line.
     *
     * @param string  $msg          the message
     *
     * @access protected
     * @return void
     */
    protected function Log($msg, $error = false) {
        echo $msg . PHP_EOL;
    }

    /**
     * Writes a message to STDERR and terminates the script.
     *
     * @param string  $msg          the message
     *
     * @access protected
     * @return void
     */
    protected function Terminate($msg) {
        fwrite(STDERR, $msg);
        echo PHP_EOL.PHP_EOL;
        exit(1);
    }

    /**
     * Gets the ID of the hidden folder. If $doCreate is true (or not set) the
     * folder will be created if it does not yet exist.
     *
     * @param boolean $doCreate     Creates the folder if it does not exist, default: true
     *
     * @access protected
     * @return string
     */
    protected function getFolderId($doCreate = true) {
        $id = $this->GetHiddenFolderId();
        if (!$id) {
            if ($doCreate)
                $id = $this->CreateHiddenFolder();
            else
                $id = "<does not yet exist>";
        }
        return $id;
    }

    /**
     * Returns the configured hash field from the GABEntry.
     * If it is not available the method returns false.
     *
     * @param GABEntry $gabEntry
     *
     * @access protected
     * @return string|boolean
     */
    protected function getHashFieldValue($gabEntry) {
        if (property_exists($gabEntry, $this->hashFieldId)) {
            return $gabEntry->{$this->hashFieldId};
        }
        else {
            $this->Log("getHashFieldValue: error, configured UNIQUEID is not set in GAB entry.");
            return false;
        }
    }

    /**
     * Calculated the chunk-id of a value.
     *
     * @param string $value
     *
     * @access protected
     * @return number
     */
    protected function calculateChunkId($value) {
        $hash = sprintf("%u", crc32($value));
        return fmod($hash, AMOUNT_OF_CHUNKS);
    }


    /*********************************
     * Abstract methods
     *********************************/

    /**
     * Creates the hidden folder.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function CreateHiddenFolder();

    /**
     * Deletes the hidden folder.
     *
     * @param string $folderid
     *
     * @access protected
     * @return boolean
     */
    protected abstract function DeleteHiddenFolder($folderid);

    /**
     * Returns the internal identifier (folder-id) of the hidden folder.
     *
     * @access protected
     * @return string
    */
    protected abstract function GetHiddenFolderId();

    /**
     * Removes all messages that have not the same chunkType (chunk configuration changed!)
     *
     * @param string $folderid
     *
     * @access protected
     * @return boolean
     */
    protected abstract function ClearFolderContents($folderid);

    /**
     * Removes all messages that do not match the current ChunkType.
     *
     * @param string $folderid
     *
     * @access protected
     * @return boolean
     */
    protected abstract function ClearAllNotCurrentChunkType($folderid);

    /**
     * Returns a list with all GAB entries or a single entry specified by $uniqueId.
     * The search for that single entry is done using the configured UNIQUEID parameter.
     *
     * @param string $uniqueId      A value to be found in the configured UNIQUEID.
     *                              If set, only one item is returned. If false or not set, the entire GAB is returned.
     *                              Default: false
     *
     * @access protected
     * @return array of GABEntry
     */
    protected abstract function GetGAB($uniqueId = false);

    /**
     * Returns the chunk data of the chunkId of the hidden folder.
     *
     * @param string    $folderid
     * @param string    $chunkName      The name of the chunk (used to find the chunk message).
     *                                  The name is saved in the 'subject' of the chunk message.
     *
     * @access protected
     * @return json string
     */
    protected abstract function GetChunkData($folderid, $chunkName);

    /**
     * Updates the chunk data in the hidden folder if it changed.
     * If the chunkId is not available, it's created.
     *
     * @param string    $folderid
     * @param string    $chunkName      The name of the chunk (used to find/update the chunk message).
     *                                  The name is to be saved in the 'subject' of the chunk message.
     * @param int       $amountEntries  Amount of entries in the chunkdata.
     * @param string    $chunkData      The data containing all the data.
     * @param string    $chunkCRC       A checksum of the chunk data. To be saved in the 'location' of
     *                                  the chunk message. Used to identify changed chunks.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function SetChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC);
}