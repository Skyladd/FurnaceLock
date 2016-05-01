<?php

namespace Skyladd;

use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\level\Level;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\tile\Furnace;

class FurnaceLock extends PluginBase implements Listener {
    /** @var ChestLockDatabaseManager  */
    private $databaseManager;
    private $queue;
    /** @var  FurnaceLockLogger */
    private $furnaceLockLogger;

    // Constants
    const NOT_LOCKED = -1;
    const NORMAL_LOCK = 0;
    const PASSCODE_LOCK = 1;
    const PUBLIC_LOCK = 2;

    public function onLoad()
	{
	}

	public function onEnable()
	{
        @mkdir($this->getDataFolder());
        $this->queue = [];
        $this->furnaceLockLogger = new FurnaceLockLogger($this->getDataFolder() . 'FurnaceLock.log');
        $this->databaseManager = new FurnaceLockDatabaseManager($this->getDataFolder() . 'FurnaceLock.sqlite3');
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

	public function onDisable()
	{
	}

	public function onCommand(CommandSender $sender, Command $command, $label, array $args)
	{
        if (!($sender instanceof Player)) {
            $sender->sendMessage("Must be run in the world!");
            return true;
        }
        if (isset($this->queue[$sender->getName()])) {
            $sender->sendMessage("You have already had the task to execute!");
            return true;
        }
        switch (strtolower($command->getName())) {
            case "furnace":
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case "lock":
                    case "unlock":
                    case "public":
                    case "info":
                        $this->queue[$sender->getName()] = [$option];
                        break;

                    case "passlock":
                    case "passunlock":
                        if (is_null($passcode = array_shift($args))) {
                            $sender->sendMessage("Usage: /furnace passlock <passcode>");
                            return true;
                        }
                        $this->queue[$sender->getName()] = [$option, $passcode];
                        break;

                    case "share":
                        if (is_null($target = array_shift($args))) {
                            $sender->sendMessage("Usage: /furnace share <player>");
                            return true;
                        }
                        $this->queue[$sender->getName()] = [$option, $target];
                        break;

                    default:
                        $sender->sendMessage("/furnace <lock | unlock | public | info>");
                        $sender->sendMessage("/furnace <passlock | passunlock | share>");
                        return true;
                }
                $this->furnaceLockLogger->log("[" . $sender->getName() . "] Action:Command Command:" . $command->getName() . " Args:" . implode(",", $args));
                $sender->sendMessage("[" .$option."] Touch the target furnace!");
                return true;

            case "furnaceop":
                $option = strtolower(array_shift($args));
                switch ($option) {
                    case "unlock":
                        $unlockOption =strtolower(array_shift($args));
                        switch ($unlockOption) {
                            case "a":
                            case "all":
                                $this->databaseManager->deleteAll();
                                $sender->sendMessage("Completed to unlock all furnace");
                                break;

                            case "p":
                            case "player":
                                $target = array_shift($args);
                                if (is_null($target)) {
                                    $sender->sendMessage("Specify target player!");
                                    $sender->sendMessage("/furnaceop unlock player <player>");
                                    return true;
                                }
                                $this->databaseManager->deletePlayerData($target);
                                $sender->sendMessage("Completed to unlock all $target's furnaces");
                                break;

                            default:
                                $sender->sendMessage("/furnaceop unlock <all | player>");
                                return true;
                        }
                        break;
                      
                    default:
                        $sender->sendMessage("/furnaceop <unlock>");
                        return true;
                }
                $this->furnaceLockLogger->log("[" . $sender->getName() . "] Action:Command Command:" . $command->getName() . " Args:" . implode(",", $args));
                return true;
        }
        return false;
	}

    public function onPlayerBreakBlock(BlockBreakEvent $event) {
        if ($event->getBlock()->getID() === Item::FURNACE and $this->databaseManager->isLocked($event->getBlock())) {
            $chest = $event->getBlock();
            $owner = $this->databaseManager->getOwner($chest);
            $attribute = $this->databaseManager->getAttribute($chest);
            $pairChestTile = null;
            if (($tile = $chest->getLevel()->getTile($chest)) instanceof Chest) $pairChestTile = $tile->getPair();
            if ($owner === $event->getPlayer()->getName()) {
                $this->databaseManager->unlock($chest);
                if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Unlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                $event->getPlayer()->sendMessage("Completed to unlock");
            } elseif ($attribute !== self::NOT_LOCKED and $owner !== $event->getPlayer()->getName() and !$event->getPlayer()->hasPermission("chestlock.op")) {
                $event->getPlayer()->sendMessage("The furnace has been locked");
                $event->getPlayer()->sendMessage("Try \"/furnace info\" out to get more info about the furnace");
                $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Unlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                $event->setCancelled();
            }
        }
    }

    public function onPlayerBlockPlace(BlockPlaceEvent $event) {
        // Prohibit placing furnace next to locked furnace
        if ($event->getItem()->getID() === Item::FURNACE) {
            $cs = $this->getSideChest($event->getPlayer()->getLevel(), $event->getBlock()->x, $event->getBlock()->y, $event->getBlock()->z);
            if (!is_null($cs)) {
                foreach ($cs as $c) {
                    if ($this->databaseManager->isLocked($c)) {
                        $event->getPlayer()->sendMessage("Cannot place furnace next to locked furnace");
                        $event->setCancelled();
                        return;
                    }
                }
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) {
        // Execute task
        if ($event->getBlock()->getID() === Item::FURNACE) {
            $chest = $event->getBlock();
            $owner = $this->databaseManager->getOwner($chest);
            $attribute = $this->databaseManager->getAttribute($chest);
            $pairChestTile = null;
            if (($tile = $chest->getLevel()->getTile($chest)) instanceof Chest) $pairChestTile = $tile->getPair();
            if (isset($this->queue[$event->getPlayer()->getName()])) {
                $task = $this->queue[$event->getPlayer()->getName()];
                $taskName = array_shift($task);
                switch ($taskName) {
                    case "lock":
                        if ($attribute === self::NOT_LOCKED) {
                            $this->databaseManager->normalLock($chest, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->databaseManager->normalLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage("Completed to lock");
                            $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Lock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("The furnace has already been locked");
                        }
                        break;

                    case "unlock":
                        if ($owner === $event->getPlayer()->getName() and $attribute === self::NORMAL_LOCK) {
                            $this->databaseManager->unlock($chest);
                            if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                            $event->getPlayer()->sendMessage("Completed to unlock");
                            $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Unlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("The chest is not locked with normal lock");
                        }
                        break;

                    case "public":
                        if ($attribute === self::NOT_LOCKED) {
                            $this->databaseManager->publicLock($chest, $event->getPlayer()->getName());
                            if ($pairChestTile instanceof Chest) $this->databaseManager->publicLock($pairChestTile, $event->getPlayer()->getName());
                            $event->getPlayer()->sendMessage("Completed to public lock");
                            $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Public Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("The furnace has already been locked");
                        }
                        break;

                    case "info":
                        if ($attribute !== self::NOT_LOCKED) {
                            $message = "Owner: $owner LockType: ";
                            switch ($attribute) {
                                case self::NORMAL_LOCK:
                                    $message .= "Normal";
                                    break;

                                case self::PASSCODE_LOCK:
                                    $message .= "Passcode";
                                    break;

                                case self::PUBLIC_LOCK:
                                    $message .= "Public";
                                    break;
                            }
                            $event->getPlayer()->sendMessage($message);
                            $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Info Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("The furnace is not locked");
                        }
                        break;

                    case "passlock":
                        if ($attribute === self::NOT_LOCKED) {
                            $passcode = array_shift($task);
                            $this->databaseManager->passcodeLock($chest, $event->getPlayer()->getName(), $passcode);
                            if ($pairChestTile instanceof Chest) $this->databaseManager->passcodeLock($pairChestTile, $event->getPlayer()->getName(), $passcode);
                            $event->getPlayer()->sendMessage("Completed to lock with passcode \"$passcode\"");
                            $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Passlock Passcode:$passcode Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                        } else {
                            $event->getPlayer()->sendMessage("The furnace has already been locked");
                        }
                        break;

                    case "passunlock":
                        if ($attribute === self::PASSCODE_LOCK) {
                            $passcode = array_shift($task);
                            if ($this->databaseManager->checkPasscode($chest, $passcode)) {
                                $this->databaseManager->unlock($chest);
                                if ($pairChestTile instanceof Chest) $this->databaseManager->unlock($pairChestTile);
                                $event->getPlayer()->sendMessage("Completed to unlock");
                                $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:Passunlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                            } else {
                                $event->getPlayer()->sendMessage("Failed to unlock due to wrong passcode");
                                $this->furnaceLockLogger->log("[" . $event->getPlayer()->getName() . "] Action:FailPassunlock Level:{$chest->getLevel()->getName()} Coordinate:{$chest->x},{$chest->y},{$chest->z}");
                            }
                        } else {
                            $event->getPlayer()->sendMessage("The furnace is not locked with passcode");
                        }
                        break;

                    case "share":
                        break;
                }
                $event->setCancelled();
                unset($this->queue[$event->getPlayer()->getName()]);
            } elseif($attribute !== self::NOT_LOCKED and $attribute !== self::PUBLIC_LOCK and $owner !== $event->getPlayer()->getName() and !$event->getPlayer()->hasPermission("furnacelock.op")) {
                $event->getPlayer()->sendMessage("The furnace has been locked");
                $event->getPlayer()->sendMessage("Try \"/furnace info\" to get more info about the furnace");
                $event->setCancelled();
            }
        }
    }

    private function getSideChest(Level $level, $x, $y, $z)
    {
        $sideChests = [];
        $item = $level->getBlock(new Vector3($x + 1, $y, $z));
        if ($item->getID() === Item::FURNACE) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x - 1, $y, $z));
        if ($item->getID() === Item::FURNACE) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x, $y, $z + 1));
        if ($item->getID() === Item::FURNACE) $sideChests[] = $item;
        $item = $level->getBlock(new Vector3($x, $y, $z - 1));
        if ($item->getID() === Item::FURNACE) $sideChests[] = $item;
        return empty($sideChests) ? null : $sideChests;
    }
}
