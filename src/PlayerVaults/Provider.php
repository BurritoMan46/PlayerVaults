<?php
/*
*
* Copyright (C) 2018 MangoTheDev
*
*    ___ _                                        _ _
*   / _ \ | __ _ _   _  ___ _ __/\   /\__ _ _   _| | |_ ___
*  / /_)/ |/ _" | | | |/ _ \ "__\ \ / / _" | | | | | __/ __|
* / ___/| | (_| | |_| |  __/ |   \ V / (_| | |_| | | |_\__ \
* \/    |_|\__,_|\__, |\___|_|    \_/ \__,_|\__,_|_|\__|___/
*                |___/
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Lesser General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
*
* @author MangoTheDev
* Twiter: http://twitter.com/dakeromar
*
*/
namespace PlayerVaults;

use PlayerVaults\Task\{DeleteVaultTask, FetchInventoryTask, SaveInventoryTask};

use pocketmine\block\Block;
use pocketmine\nbt\{BigEndianNBTStream, NetworkLittleEndianNBTStream};
use pocketmine\nbt\tag\{CompoundTag, IntTag, ListTag, StringTag};
use pocketmine\network\mcpe\protocol\BlockEntityDataPacket;
use pocketmine\Player;
use pocketmine\tile\Tile;
use pocketmine\utils\TextFormat as TF;

class Provider{

    const INVENTORY_HEIGHT = 2;

    const TYPE_FROM_STRING = [
        'json' => Provider::JSON,
        'yaml' => Provider::YAML,
        'yml' => Provider::YAML,
        'mysql' => Provider::MYSQL
    ];

    const JSON = 0;
    const YAML = 1;
    const MYSQL = 2;
    const UNKNOWN = 3;

    /** @var array|string */
    private $data;//data for provider

    /** @var Server */
    private $server;

    /** @var int */
    private $type = Provider::JSON;

    /** @var string */
    private $inventoryName = "";

    /** @var string[] */
    private $processing = [];//the vaults that are being saved, for safety

    public function __construct(int $type)
    {
        if($type === Provider::UNKNOWN){
            throw new \Exception("Class constant '$type' does not exist. Switching to JSON.");
            $type = Provider::JSON;
        }
        $this->type = $type;

        $core = PlayerVaults::getInstance();
        $this->server = $core->getServer();
        $this->setInventoryName($core->getFromConfig("vaultinv-name") ?? "");

        if(is_file($oldfile = $core->getDataFolder()."vaults.json")){
            $data = json_decode(file_get_contents($oldfile));
            $logger = $core->getLogger();
            foreach($data as $k => $v){
                file_put_contents($core->getDataFolder()."vaults/".$k.".json", json_encode($v, JSON_PRETTY_PRINT));
                $logger->notice("Moved $k's vault data to /vaults.");
            }
            rename($oldfile, $oldfile.".bak");
        }elseif(is_file($oldfile = $core->getDataFolder()."vaults.yml")){
            $data = yaml_parse_file($oldfile);
            $logger = $core->getLogger();
            foreach($data as $k => $v){
                yaml_emit_file($core->getDataFolder()."vaults/".$k.".yml", $v);
                $logger->notice("Moved $k's vault data to /vaults.");
            }
            rename($oldfile, $oldfile.".bak");
        }

        switch($type){
            case Provider::JSON:
            case Provider::YAML:
                $this->data = $core->getDataFolder().'vaults/';
                break;
            case Provider::MYSQL:
                $this->data = $core->getMysqlData();
                break;
        }

    }

    public function getType() : int
    {
        return $this->type;
    }

    public function markAsProcessed(string $player, string $hash) : void
    {
        if ($this->processing[$player] === $hash) {
            unset($this->processing[$player]);
        }
    }

    private function getInventoryName(int $vaultno) : string
    {
        return str_replace("{VAULTNO}", $vaultno, $this->inventoryName);
    }

    public function setInventoryName(string $name) : void
    {
        $this->inventoryName = $name;
    }

    public function sendContents($player, int $number = 1, ?string $viewer = null) : void
    {
        $name = $player instanceof Player ? $player->getLowerCaseName() : strtolower($player);
        $this->server->getScheduler()->scheduleAsyncTask(new FetchInventoryTask($name, $this->type, $number, $viewer ?? $name, $this->data));
    }

    public function get(Player $player, array $contents, int $number = 1, ?string $vaultof = null) : ?VaultInventory
    {
        $vaultof = $vaultof ?? $player->getLowerCaseName();

        if(isset($this->processing[$vaultof])){
            $player->sendMessage(TF::RED."You cannot open this vault as it is already in use by ".TF::GRAY.$this->processing[$vaultof].TF::RED.".");
            return null;
        }

        $this->processing[$vaultof] = $player->getLowerCaseName();

        $pos = $player->asPosition();
        //Position->floor() returns Vector3
        $pos->x = (int) $pos->x;
        $pos->z = (int) $pos->z;
        $pos->y += Provider::INVENTORY_HEIGHT;
        $pos->y = (int) $pos->y;

        $pos->level->sendBlocks([$player], [Block::get(Block::CHEST, 0, $pos)]);

        $inventory = new VaultInventory($pos, $vaultof, $number);
        $inventory->setContents($contents);

        $player->dataPacket($this->createVaultPacket($inventory, $this->getInventoryName($number)));
        return $inventory;
    }

    private function createVaultPacket(VaultInventory $inventory, ?string $inventoryName = null) : BlockEntityDataPacket
    {
        $pos = $inventory->getHolder();

        $pk = new BlockEntityDataPacket();
        $pk->x = $pos->x;
        $pk->y = $pos->y;
        $pk->z = $pos->z;

        $tag = new CompoundTag("", [new StringTag("id", Tile::CHEST)]);
        if($inventoryName !== null){
            $tag->setString("CustomName", $inventoryName);
        }

        $nbtWriter = new NetworkLittleEndianNBTStream();
        $nbtWriter->setData($tag);//we don't need to add x, y and z... it's only used for saving but we aren't saving vault tiles in the Level.
        $pk->namedtag = $nbtWriter->write();

        return $pk;
    }

    public function saveContents(VaultInventory $inventory) : void
    {
        $player = $inventory->getVaultOf();

        $contents = $inventory->getContents();
        foreach($contents as $slot => &$item){
            $item = $item->nbtSerialize($slot);
        }

        $nbt = new BigEndianNBTStream();
        $nbt->setData(new CompoundTag("Items", [new ListTag("ItemList", $contents)]));
        $contents = $nbt->writeCompressed(ZLIB_ENCODING_DEFLATE);//maybe do compression in SaveInventoryTask?

        $this->processing[$player] = SaveInventoryTask::class;
        $this->server->getScheduler()->scheduleAsyncTask(new SaveInventoryTask($player, $this->type, $this->data, $inventory->getNumber(), $contents));
    }

    public function deleteVault(string $player, int $vault) : void
    {
        $this->processing[$player] = DeleteVaultTask::class;
        $this->server->getScheduler()->scheduleAsyncTask(new DeleteVaultTask($player, $this->type, $vault, $this->data));
    }
}
