<?php
declare(strict_types=1);
namespace MyPlot;

use jasonwynn10\MyPlot\utils\AsyncVariants;
use muqsit\worldstyler\Selection;
use muqsit\worldstyler\shapes\CommonShape;
use muqsit\worldstyler\shapes\Cuboid;
use muqsit\worldstyler\WorldStyler;
use MyPlot\events\MyPlotClearEvent;
use MyPlot\events\MyPlotCloneEvent;
use MyPlot\events\MyPlotDisposeEvent;
use MyPlot\events\MyPlotFillEvent;
use MyPlot\events\MyPlotMergeEvent;
use MyPlot\events\MyPlotResetEvent;
use MyPlot\events\MyPlotSaveEvent;
use MyPlot\events\MyPlotSettingEvent;
use MyPlot\events\MyPlotTeleportEvent;
use MyPlot\plot\BasePlot;
use MyPlot\plot\MergedPlot;
use MyPlot\plot\SinglePlot;
use MyPlot\provider\DataProvider;
use MyPlot\provider\InternalEconomyProvider;
use MyPlot\task\ClearPlotTask;
use MyPlot\task\FillPlotTask;
use MyPlot\task\RoadFillTask;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\biome\Biome;
use pocketmine\world\biome\BiomeRegistry;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use SOFe\AwaitGenerator\Await;

final class InternalAPI{
	/** @var PlotLevelSettings[] $levels */
	private array $levels = [];
	private DataProvider $dataProvider;
	private array $populatingCache = [];

	public function __construct(private MyPlot $plugin, private ?InternalEconomyProvider $economyProvider){
		$plugin->getLogger()->debug(TF::BOLD . "Loading Data Provider settings");
		$this->dataProvider = new DataProvider($plugin, $this);
	}

	public function getEconomyProvider() : ?InternalEconomyProvider{
		return $this->economyProvider;
	}

	public function setEconomyProvider(?InternalEconomyProvider $economyProvider) : void{
		$this->economyProvider = $economyProvider;
	}

	public function getAllLevelSettings() : array{
		return $this->levels;
	}

	public function getLevelSettings(string $levelName) : ?PlotLevelSettings{
		return $this->levels[$levelName] ?? null;
	}

	public function addLevelSettings(string $levelName, PlotLevelSettings $settings) : void{
		$this->levels[$levelName] = $settings;
	}

	public function unloadLevelSettings(string $levelName) : bool{
		if(isset($this->levels[$levelName])){
			unset($this->levels[$levelName]);
			return true;
		}
		return false;
	}

	/**
	 * @param SinglePlot    $plot
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function savePlot(SinglePlot $plot, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generatePlotsToSave($plot),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generatePlotsToSave(SinglePlot $plot) : \Generator{
		$ev = new MyPlotSaveEvent($plot);
		$ev->call();
		$plot = $ev->getPlot();
		if($plot instanceof MergedPlot){
			$failed = false;
			for($x = $plot->X; $x < $plot->xWidth + $plot->X; ++$x){
				for($z = $plot->Z; $z < $plot->zWidth + $plot->Z; ++$z){
					$newPlot = clone $plot;
					$newPlot->X = $x;
					$newPlot->Z = $z;
					if(yield from $this->dataProvider->savePlot($newPlot)){
						$failed = true;
					}
				}
			}
			return $failed;
		}
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param string        $username
	 * @param string|null   $levelName
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(array<BasePlot>): void)|null $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null  $catches
	 */
	public function getPlotsOfPlayer(string $username, ?string $levelName, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generatePlotsOfPlayer($username, $levelName),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	/**
	 * @param string      $username
	 * @param string|null $levelName
	 *
	 * @return \Generator<array<BasePlot>>
	 */
	public function generatePlotsOfPlayer(string $username, ?string $levelName) : \Generator{
		return yield from $this->dataProvider->getPlotsByOwner($username, $levelName ?? '');
	}

	/**
	 * @param string        $levelName
	 * @param int           $limitXZ
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(BasePlot): void)|null $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null    $catches
	 */
	public function getNextFreePlot(string $levelName, int $limitXZ, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateNextFreePlot($levelName, $limitXZ),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateNextFreePlot(string $levelName, int $limitXZ) : \Generator{
		return yield from $this->dataProvider->getNextFreePlot($levelName, $limitXZ);
	}

	/**
	 * @param BasePlot      $plot
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(SinglePlot|null): void)|null   $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function getPlot(BasePlot $plot, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generatePlot($plot),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	/**
	 * @param BasePlot $plot
	 *
	 * @return \Generator<BasePlot>
	 */
	public function generatePlot(BasePlot $plot) : \Generator{
		return yield from $this->dataProvider->getMergedPlot($plot);
	}

	public function getPlotFast(float &$x, float &$z, PlotLevelSettings $plotLevel) : ?BasePlot{
		$plotSize = $plotLevel->plotSize;
		$roadWidth = $plotLevel->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		if($x >= 0){
			$difX = $x % $totalSize;
			$x = (int) floor($x / $totalSize);
		}else{
			$difX = abs(($x - $plotSize + 1) % $totalSize);
			$x = (int) ceil(($x - $plotSize + 1) / $totalSize);
		}
		if($z >= 0){
			$difZ = $z % $totalSize;
			$z = (int) floor($z / $totalSize);
		}else{
			$difZ = abs(($z - $plotSize + 1) % $totalSize);
			$z = (int) ceil(($z - $plotSize + 1) / $totalSize);
		}
		if(($difX > $plotSize - 1) or ($difZ > $plotSize - 1))
			return null;

		return new BasePlot($plotLevel->name, $x, $z);
	}

	public function getPlotFromCache(BasePlot $plot, bool $orderCachePopulation = true) : BasePlot{
		$cachedPlot = $this->dataProvider->getPlotFromCache($plot->levelName, $plot->X, $plot->Z);
		if(!$cachedPlot instanceof SinglePlot and $orderCachePopulation and !in_array("{$plot->levelName}:{$plot->X}:{$plot->Z}", $this->populatingCache, true)){
			$index = count($this->populatingCache);
			$this->populatingCache[] = "{$plot->levelName}:{$plot->X}:{$plot->Z}";
			Await::g2c(
				$this->generatePlot($plot),
				function(?BasePlot $cachedPlot) use ($index) : void{
					unset($this->populatingCache[$index]);
				},
				function(\Throwable $e) use ($plot, $index) : void{
					$this->plugin->getLogger()->debug("Plot {$plot->X};{$plot->Z} could not be generated");
					unset($this->populatingCache[$index]);
				}
			);
		}
		return $cachedPlot;
	}

	/**
	 * @param Position      $position
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(BasePlot): void)|null   $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function getPlotByPosition(Position $position, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generatePlotByPosition($position),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generatePlotByPosition(Position $position) : \Generator{
		$x = $position->x;
		$z = $position->z;
		$levelName = $position->getWorld()->getFolderName();
		$plotLevel = $this->getLevelSettings($levelName);
		if($plotLevel === null)
			return null;

		$plot = $this->getPlotFast($x, $z, $plotLevel);
		if($plot !== null)
			return yield from $this->generatePlot($plot);
		return null;
	}

	/**
	 * @param BasePlot $plot
	 *
	 * @return Position
	 */
	public function getPlotPosition(BasePlot $plot) : Position{
		$plotLevel = $this->getLevelSettings($plot->levelName);
		$plotSize = $plotLevel->plotSize;
		$roadWidth = $plotLevel->roadWidth;
		$totalSize = $plotSize + $roadWidth;
		$x = $totalSize * $plot->X;
		$z = $totalSize * $plot->Z;
		$level = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		return new Position($x, $plotLevel->groundHeight, $z, $level);
	}

	public function isPositionBorderingPlot(Position $position) : bool{
		return $this->getPlotBorderingPosition($position) instanceof BasePlot;
	}

	public function getPlotBorderingPosition(Position $position) : ?BasePlot{
		if(!$position->isValid())
			return null;
		foreach(Facing::HORIZONTAL as $i){
			$pos = $position->getSide($i);
			$x = $pos->x;
			$z = $pos->z;
			$levelName = $pos->getWorld()->getFolderName();

			$plotLevel = $this->getLevelSettings($levelName);
			if($plotLevel === null)
				return null;

			return $this->getPlotFast($x, $z, $plotLevel);
			// TODO: checks for merged plots
		}
		return null;
	}

	public function getPlotBB(BasePlot $plot) : ?AxisAlignedBB{
		$plotLevel = $this->getLevelSettings($plot->levelName);
		if($plotLevel === null)
			return null;

		$plotSize = $plotLevel->plotSize;
		$totalSize = $plotSize + $plotLevel->roadWidth;
		$pos = $this->getPlotPosition($plot);
		$xMax = $pos->x + $plotSize - 1;
		$zMax = $pos->z + $plotSize - 1;
		if($plot instanceof MergedPlot){
			$xMax = $pos->x + $totalSize * $plot->xWidth - 1;
			$zMax = $pos->z + $totalSize * $plot->zWidth - 1;
		}

		return new AxisAlignedBB(
			$pos->x,
			$pos->getWorld()->getMinY(),
			$pos->z,
			$xMax,
			$pos->getWorld()->getMaxY(),
			$zMax
		);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param int           $direction
	 * @param int           $maxBlocksPerTick
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function mergePlots(SinglePlot $plot, int $direction, int $maxBlocksPerTick, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateMergePlots($plot, $direction, $maxBlocksPerTick),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateMergePlots(SinglePlot $plot, int $direction, int $maxBlocksPerTick) : \Generator{
		$plotLevel = $this->getLevelSettings($plot->levelName);
		if($plotLevel === null)
			return false;

		$plot = yield from $this->generatePlot($plot);

		/** @var BasePlot[] $toMerge */
		$toMerge[] = $plot->getSide($direction);
		if($plot instanceof MergedPlot){
			$toMerge[] = $plot->getSide(
				Facing::rotateY($direction, Facing::isPositive($direction)),
				Facing::axis($direction) === Axis::X ? $plot->xWidth : $plot->zWidth
			);
		}

		/** @var SinglePlot[] $toMerge */
		$toMerge = yield AsyncVariants::array_map(
			function(SinglePlot $plot) : \Generator{
				return yield from $this->generatePlot($plot);
			},
			$toMerge
		);

		foreach($toMerge as $newPlot){
			if($newPlot === null or
				$newPlot instanceof MergedPlot or
				$newPlot->levelName !== $plot->levelName or
				$newPlot->owner !== $plot->owner
			)
				return false;
		}

		if(!$plot instanceof MergedPlot){
			$plot = MergedPlot::fromSingle(
				$plot,
				Facing::axis($direction) === Axis::X ? count($toMerge) : 1,
				Facing::axis($direction) === Axis::Z ? count($toMerge) : 1
			);
		}

		$ev = new MyPlotMergeEvent($plot, $direction, $toMerge);
		$ev->call();
		if($ev->isCancelled())
			return false;

		$styler = $this->plugin->getServer()->getPluginManager()->getPlugin("WorldStyler");
		if($this->plugin->getConfig()->get("FastClearing", false) === true and $styler instanceof WorldStyler) {
			$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->levelName);
			if($world === null)
				return false;

			$plotSize = $plotLevel->plotSize;
			$totalSize = $plotSize + $plotLevel->roadWidth;

			$aabb = $this->getPlotBB($plot);

			$aabbExpanded = $aabb->expandedCopy(1, 0, 1); // expanded to include plot borders
			foreach($world->getEntities() as $entity){
				if($aabbExpanded->isVectorInXZ($entity->getPosition())){
					if(!$entity instanceof Player){
						$entity->flagForDespawn();
					}else{
						$this->teleportPlayerToPlot($entity, $plot, false);
					}
				}
			}

			$aabb = match ($direction) {
				Facing::NORTH => new AxisAlignedBB(
					$aabb->minX,
					$aabb->minY,
					$aabb->minZ - $plotLevel->roadWidth,
					$aabb->maxX,
					$aabb->maxY,
					$aabb->minZ
				),
				Facing::EAST => new AxisAlignedBB(
					$aabb->maxX + ($totalSize * ($plot->xWidth - 1)),
					$aabb->minY,
					$aabb->minZ,
					$aabb->maxX + ($totalSize * ($plot->xWidth - 1)) + $plotLevel->roadWidth,
					$aabb->maxY,
					$aabb->maxZ
				),
				Facing::SOUTH => new AxisAlignedBB(
					$aabb->minX,
					$aabb->minY,
					$aabb->maxZ + ($totalSize * ($plot->zWidth - 1)),
					$aabb->maxX,
					$aabb->maxY,
					$aabb->maxZ + ($totalSize * ($plot->zWidth - 1)) + $plotLevel->roadWidth
				),
				Facing::WEST => new AxisAlignedBB(
					$aabb->minX - $plotLevel->roadWidth,
					$aabb->minY,
					$aabb->minZ,
					$aabb->minX,
					$aabb->maxY,
					$aabb->maxZ
				),
				default => throw new \InvalidArgumentException("Invalid direction $direction")
			};

			// above ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->maxY, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 2, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, VanillaBlocks::AIR()->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			if(Facing::axis($direction) === Axis::X) {
				// min border
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->minZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);

				// max border
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->maxZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);
			}elseif(Facing::axis($direction) === Axis::Z){
				// min border
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);

				// max border
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);
			}

			// ground height
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->plotFloorBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// below ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight - 1, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->plotFillBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// bottom block
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $aabb->minY, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->bottomBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			$i = 0;
			foreach($toMerge as $newPlot) {
				$newPlot = MergedPlot::fromSingle(
					SinglePlot::fromBase($newPlot),
					Facing::axis($direction) === Axis::X ? $i : 1,
					Facing::axis($direction) === Axis::Z ? $i : 1
				);
				$direction = Facing::rotateY($direction, true);

				$aabb = $this->getPlotBB($newPlot);

				$aabbExpanded = $aabb->expandedCopy(1, 0, 1); // expanded to include plot borders
				foreach($world->getEntities() as $entity){
					if($aabbExpanded->isVectorInXZ($entity->getPosition())){
						if(!$entity instanceof Player){
							$entity->flagForDespawn();
						}else{
							$this->teleportPlayerToPlot($entity, $newPlot, false);
						}
					}
				}

				$aabb = match ($direction) {
					Facing::NORTH => new AxisAlignedBB(
						$aabb->minX,
						$aabb->minY,
						$aabb->minZ - $plotLevel->roadWidth,
						$aabb->maxX,
						$aabb->maxY,
						$aabb->minZ
					),
					Facing::EAST => new AxisAlignedBB(
						$aabb->maxX + ($totalSize * ($newPlot->xWidth - 1)),
						$aabb->minY,
						$aabb->minZ,
						$aabb->maxX + ($totalSize * ($newPlot->xWidth - 1)) + $plotLevel->roadWidth,
						$aabb->maxY,
						$aabb->maxZ
					),
					Facing::SOUTH => new AxisAlignedBB(
						$aabb->minX,
						$aabb->minY,
						$aabb->maxZ + ($totalSize * ($newPlot->zWidth - 1)),
						$aabb->maxX,
						$aabb->maxY,
						$aabb->maxZ + ($totalSize * ($newPlot->zWidth - 1)) + $plotLevel->roadWidth
					),
					Facing::WEST => new AxisAlignedBB(
						$aabb->minX - $plotLevel->roadWidth,
						$aabb->minY,
						$aabb->minZ,
						$aabb->minX,
						$aabb->maxY,
						$aabb->maxZ
					),
					default => throw new \InvalidArgumentException("Invalid direction $direction")
				};

				// above ground
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $aabb->maxY, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 2, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, VanillaBlocks::AIR()->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);

				if(Facing::isPositive($direction)) {
					if(Facing::axis($direction) === Axis::X) {
						// max border
						$selection = $styler->getSelection(99998) ?? new Selection(99998);
						$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->maxZ));
						$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->maxZ));
						$cuboid = Cuboid::fromSelection($selection);
						//$cuboid = $cuboid->async();
						$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
							$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
						});
						$styler->removeSelection(99998);
					}elseif(Facing::axis($direction) === Axis::Z){
						// max border
						$selection = $styler->getSelection(99998) ?? new Selection(99998);
						$selection->setPosition(1, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->minZ));
						$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->maxZ));
						$cuboid = Cuboid::fromSelection($selection);
						//$cuboid = $cuboid->async();
						$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
							$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
						});
						$styler->removeSelection(99998);
					}
				}else{
					if(Facing::axis($direction) === Axis::X) {
						// min border
						$selection = $styler->getSelection(99998) ?? new Selection(99998);
						$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->minZ));
						$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->minZ));
						$cuboid = Cuboid::fromSelection($selection);
						//$cuboid = $cuboid->async();
						$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
							$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
						});
						$styler->removeSelection(99998);
					}elseif(Facing::axis($direction) === Axis::Z){
						// min border
						$selection = $styler->getSelection(99998) ?? new Selection(99998);
						$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->minZ));
						$selection->setPosition(2, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->maxZ));
						$cuboid = Cuboid::fromSelection($selection);
						//$cuboid = $cuboid->async();
						$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
							$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
						});
						$styler->removeSelection(99998);
					}
				}

				// ground height
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->plotFloorBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);

				// below ground
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight - 1, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->plotFillBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);

				// bottom block
				$selection = $styler->getSelection(99998) ?? new Selection(99998);
				$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
				$selection->setPosition(2, new Vector3($aabb->maxX, $aabb->minY, $aabb->maxZ));
				$cuboid = Cuboid::fromSelection($selection);
				//$cuboid = $cuboid->async();
				$cuboid->set($world, $plotLevel->bottomBlock->getFullId(), function(float $time, int $changed) : void{
					$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
				});
				$styler->removeSelection(99998);

				++$i;
			}

			foreach($this->getPlotChunks($plot) as [$chunkX, $chunkZ, $chunk]){
				if($chunk === null)
					continue;
				foreach($chunk->getTiles() as $tile)
					if($aabb->isVectorInXZ($tile->getPosition()))
						$tile->close();
				$world->setChunk($chunkX, $chunkZ, $chunk);
			}

			return yield from $this->dataProvider->mergePlots($plot, ...$toMerge);
		}

		$this->plugin->getScheduler()->scheduleTask(new RoadFillTask($this->plugin, $plot, $direction, RoadFillTask::BOTH_BORDERS, $maxBlocksPerTick));

		for($i = 0; $i < count($toMerge) - 1; ++$i){
			$this->plugin->getScheduler()->scheduleTask(new RoadFillTask(
				$this->plugin,
				MergedPlot::fromSingle(
					SinglePlot::fromBase($toMerge[$i]),
					Facing::axis($direction) === Axis::X ? $i : 1,
					Facing::axis($direction) === Axis::Z ? $i : 1
				),
				Facing::rotateY($direction, true),
				Facing::isPositive($direction) ? RoadFillTask::HIGH_BORDER : RoadFillTask::LOW_BORDER,
				$maxBlocksPerTick
			));
		}

		return yield from $this->dataProvider->mergePlots($plot, ...$toMerge);
	}

	public function teleportPlayerToPlot(Player $player, BasePlot $plot, bool $center = false) : bool{
		$ev = new MyPlotTeleportEvent($plot, $player, $center);
		$ev->call();
		if($ev->isCancelled())
			return false;

		$plotLevel = $this->getLevelSettings($plot->levelName);
		$plotSize = $plotLevel->plotSize;
		$totalWidth = $plotSize + $plotLevel->roadWidth;
		$pos = $this->getPlotPosition($plot);
		$pos->y += 1.5;

		if($center and $plot instanceof MergedPlot){
			$pos->x += $pos->x + ($totalWidth * $plot->xWidth) / 2;
			$pos->z += $pos->z + ($totalWidth * $plot->zWidth) / 2;
		}elseif($center){
			$pos->x += $plotSize / 2;
			$pos->z += $plotSize / 2;
		}elseif($plot instanceof MergedPlot){
			$pos->x += $pos->x + ($totalWidth * $plot->xWidth) / 2;
			$pos->z -= 1;
		}else{
			$pos->x += $plotSize / 2;
			$pos->z -= 1;
		}
		return $player->teleport($pos);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param string        $claimer
	 * @param string        $plotName
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function claimPlot(SinglePlot $plot, string $claimer, string $plotName, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateClaimPlot($plot, $claimer, $plotName),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateClaimPlot(SinglePlot $plot, string $claimer, string $plotName) : \Generator{
		$newPlot = clone $plot;
		$newPlot->owner = $claimer;
		$newPlot->helpers = [];
		$newPlot->denied = [];
		if($plotName !== "")
			$newPlot->name = $plotName;
		$newPlot->price = 0;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		return yield from $this->generatePlotsToSave($ev->getPlot()); // TODO: figure out why cache is not updated
	}

	/**
	 * @param SinglePlot    $plot
	 * @param string        $newName
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function renamePlot(SinglePlot $plot, string $newName, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateRenamePlot($plot, $newName),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateRenamePlot(SinglePlot $plot, string $newName) : \Generator{
		$newPlot = clone $plot;
		$newPlot->name = $newName;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		return yield from $this->generatePlotsToSave($ev->getPlot());
	}

	public function clonePlot(SinglePlot $plotFrom, SinglePlot $plotTo) : bool{
		$styler = $this->plugin->getServer()->getPluginManager()->getPlugin("WorldStyler");
		if(!$styler instanceof WorldStyler)
			return false;

		$ev = new MyPlotCloneEvent($plotFrom, $plotTo);
		$ev->call();
		if($ev->isCancelled())
			return false;

		$plotFrom = $ev->getPlot();
		$plotTo = $ev->getClonePlot();

		if($this->getLevelSettings($plotFrom->levelName) === null or $this->getLevelSettings($plotTo->levelName) === null)
			return false;

		$fromMerge = $plotFrom instanceof MergedPlot;
		$toMerge = $plotTo instanceof MergedPlot;
		if(($fromMerge and !$toMerge) or (!$toMerge and $fromMerge))
			return false;
		if($fromMerge and $toMerge and ($plotFrom->xWidth !== $plotTo->xWidth or $plotFrom->zWidth !== $plotTo->zWidth))
			return false;

		$selection = $styler->getSelection(99997) ?? new Selection(99997);

		$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($plotFrom->levelName);
		$fromAABB = $this->getPlotBB($plotFrom);

		$vec1 = new Vector3($fromAABB->minX - 1, $fromAABB->minY, $fromAABB->minZ - 1);
		$selection->setPosition(1, $vec1);
		$selection->setPosition(2, new Vector3($fromAABB->maxX, $fromAABB->maxY, $fromAABB->maxZ));
		$cuboid = Cuboid::fromSelection($selection);
		//$cuboid = $cuboid->async(); // do not use async because WorldStyler async is very broken right now
		$cuboid->copy($world, $vec1, function(float $time, int $changed) : void{
			$this->plugin->getLogger()->debug(TF::GREEN . 'Copied ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's to the MyPlot clipboard.');
		});

		$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($plotTo->levelName);
		$toAABB = $this->getPlotBB($plotTo);
		foreach($world->getEntities() as $entity){
			if($toAABB->isVectorInXZ($entity->getPosition())){
				if($entity instanceof Player){
					$this->teleportPlayerToPlot($entity, $plotTo, false);
				}else{
					$entity->flagForDespawn();
				}
			}
		}

		$vec1 = new Vector3($toAABB->minX - 1, $toAABB->minY, $toAABB->minZ - 1);
		$selection->setPosition(1, $vec1);
		$selection->setPosition(2, new Vector3($toAABB->maxX, $toAABB->maxY, $toAABB->maxZ));
		$commonShape = CommonShape::fromSelection($selection);
		//$commonShape = $commonShape->async(); // do not use async because WorldStyler async is very broken right now
		$commonShape->paste($world, $vec1, true, function(float $time, int $changed) : void{
			$this->plugin->getLogger()->debug(TF::GREEN . 'Pasted ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's from the MyPlot clipboard.');
		});
		$styler->removeSelection(99997);

		foreach($this->getPlotChunks($plotTo) as [$chunkX, $chunkZ, $chunk]){
			$world->setChunk($chunkX, $chunkZ, $chunk);
		}
		return true;
	}

	public function clearPlot(BasePlot $plot, int $maxBlocksPerTick) : bool{
		$ev = new MyPlotClearEvent($plot, $maxBlocksPerTick);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		$maxBlocksPerTick = $ev->getMaxBlocksPerTick();

		$plotLevel = $this->getLevelSettings($plot->levelName);
		if($plotLevel === null)
			return false;

		$styler = $this->plugin->getServer()->getPluginManager()->getPlugin("WorldStyler");
		if($this->plugin->getConfig()->get("FastClearing", false) === true and $styler instanceof WorldStyler){
			$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->levelName);
			if($world === null)
				return false;

			$aabb = $this->getPlotBB($plot)->expand(1, 0, 1); // expanded to include plot borders
			foreach($world->getEntities() as $entity){
				if($aabb->isVectorInXZ($entity->getPosition())){
					if(!$entity instanceof Player){
						$entity->flagForDespawn();
					}else{
						$this->teleportPlayerToPlot($entity, $plot, false);
					}
				}
			}

			// Above ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $aabb->maxY, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, VanillaBlocks::AIR()->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Ground Surface
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->plotFloorBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY + 1, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight - 1, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->plotFillBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Bottom of world
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $aabb->minY, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->bottomBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Border +X
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Border +Z
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $plotLevel->groundHeight + 1, $aabb->maxZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $plotLevel->groundHeight + 1, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Border -X
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->minX, $aabb->minY, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			// Border -Z
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $aabb->minY, $aabb->minZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotLevel->wallBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			foreach($this->getPlotChunks($plot) as [$chunkX, $chunkZ, $chunk]){
				if($chunk === null)
					continue;
				foreach($chunk->getTiles() as $tile)
					if($aabb->isVectorInXZ($tile->getPosition()))
						$tile->close();
				$world->setChunk($chunkX, $chunkZ, $chunk);
			}
			return true;
		}
		$this->plugin->getScheduler()->scheduleTask(new ClearPlotTask($this->plugin, $plot, $maxBlocksPerTick));
		return true;
	}

	public function fillPlot(BasePlot $plot, Block $plotFillBlock, int $maxBlocksPerTick) : bool{
		$ev = new MyPlotFillEvent($plot, $maxBlocksPerTick);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		$maxBlocksPerTick = $ev->getMaxBlocksPerTick();

		$plotLevel = $this->getLevelSettings($plot->levelName);
		if($plotLevel === null)
			return false;

		$styler = $this->plugin->getServer()->getPluginManager()->getPlugin("WorldStyler");
		if($this->plugin->getConfig()->get("FastFilling", false) === true and $styler instanceof WorldStyler){
			$world = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->levelName);
			if($world === null)
				return false;

			$aabb = $this->getPlotBB($plot);
			$aabb->maxY = $this->getLevelSettings($plot->levelName)->groundHeight;
			foreach($world->getEntities() as $entity){
				if($aabb->isVectorInside($entity->getPosition())){
					if(!$entity instanceof Player){
						$entity->flagForDespawn();
					}else{
						$this->teleportPlayerToPlot($entity, $plot, false);
					}
				}
			}

			// Ground
			$selection = $styler->getSelection(99998) ?? new Selection(99998);
			$selection->setPosition(1, new Vector3($aabb->minX, $aabb->minY + 1, $aabb->minZ));
			$selection->setPosition(2, new Vector3($aabb->maxX, $aabb->maxY, $aabb->maxZ));
			$cuboid = Cuboid::fromSelection($selection);
			//$cuboid = $cuboid->async();
			$cuboid->set($world, $plotFillBlock->getFullId(), function(float $time, int $changed) : void{
				$this->plugin->getLogger()->debug('Set ' . number_format($changed) . ' blocks in ' . number_format($time, 10) . 's');
			});
			$styler->removeSelection(99998);

			foreach($this->getPlotChunks($plot) as [$chunkX, $chunkZ, $chunk]){
				$world->setChunk($chunkX, $chunkZ, $chunk);
			}
			return true;
		}
		$this->plugin->getScheduler()->scheduleTask(new FillPlotTask($this->plugin, $plot, $plotFillBlock, $maxBlocksPerTick));
		return true;
	}

	/**
	 * @param SinglePlot    $plot
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null    $catches
	 */
	public function disposePlot(BasePlot $plot, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateDisposePlot($plot),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateDisposePlot(BasePlot $plot) : \Generator{
		$ev = new MyPlotDisposeEvent($plot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->deletePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param int           $maxBlocksPerTick
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function resetPlot(SinglePlot $plot, int $maxBlocksPerTick, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateResetPlot($plot, $maxBlocksPerTick),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateResetPlot(SinglePlot $plot, int $maxBlocksPerTick) : \Generator{
		$ev = new MyPlotResetEvent($plot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		if(!yield from $this->generateDisposePlot($plot)){
			return false;
		}
		if(!$this->clearPlot($plot, $maxBlocksPerTick)){
			yield from $this->generatePlotsToSave($plot);
			return false;
		}
		return true;
	}

	/**
	 * @param SinglePlot    $plot
	 * @param Biome         $biome
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null    $catches
	 */
	public function setPlotBiome(SinglePlot $plot, Biome $biome, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generatePlotBiome($plot, $biome),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generatePlotBiome(SinglePlot $plot, Biome $biome) : \Generator{
		$newPlot = clone $plot;
		$newPlot->biome = str_replace(" ", "_", strtoupper($biome->getName()));
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		if($this->getLevelSettings($plot->levelName) === null)
			return false;

		$level = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		if($level === null)
			return false;

		if(defined(BiomeIds::class . "::" . $plot->biome) and is_int(constant(BiomeIds::class . "::" . $plot->biome))){
			$biome = constant(BiomeIds::class . "::" . $plot->biome);
		}else{
			$biome = BiomeIds::PLAINS;
		}
		$biome = BiomeRegistry::getInstance()->getBiome($biome);

		$aabb = $this->getPlotBB($plot);
		foreach($this->getPlotChunks($plot) as [$chunkX, $chunkZ, $chunk]){
			if($chunk === null)
				continue;

			for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
				for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
					if($aabb->isVectorInXZ(new Vector3(($chunkX << Chunk::COORD_MASK) + $x, 0, ($chunkZ << Chunk::COORD_MASK) + $z))){
						$chunk->setBiomeId($x, $z, $biome->getId());
					}
				}
			}
			$level->setChunk($chunkX, $chunkZ, $chunk);
		}
		return yield from $this->generatePlotsToSave($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param bool          $pvp
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function setPlotPvp(SinglePlot $plot, bool $pvp, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generatePlotPvp($plot, $pvp),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generatePlotPvp(SinglePlot $plot, bool $pvp) : \Generator{
		$newPlot = clone $plot;
		$newPlot->pvp = $pvp;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param string        $player
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function addPlotHelper(SinglePlot $plot, string $player, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateAddPlotHelper($plot, $player),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateAddPlotHelper(SinglePlot $plot, string $player) : \Generator{
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$newPlot->addHelper($player) ? $ev->uncancel() : $ev->cancel();
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param string        $player
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function removePlotHelper(SinglePlot $plot, string $player, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateRemovePlotHelper($plot, $player),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateRemovePlotHelper(SinglePlot $plot, string $player) : \Generator{
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$newPlot->removeHelper($player) ? $ev->uncancel() : $ev->cancel();
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param string        $player
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function addPlotDenied(SinglePlot $plot, string $player, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateAddPlotDenied($plot, $player),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateAddPlotDenied(SinglePlot $plot, string $player) : \Generator{
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$newPlot->denyPlayer($player) ? $ev->uncancel() : $ev->cancel();
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param string        $player
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function removePlotDenied(SinglePlot $plot, string $player, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateRemovePlotDenied($plot, $player),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateRemovePlotDenied(SinglePlot $plot, string $player) : \Generator{
		$newPlot = clone $plot;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$newPlot->unDenyPlayer($player) ? $ev->uncancel() : $ev->cancel();
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param int           $price
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function sellPlot(SinglePlot $plot, int $price, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateSellPlot($plot, $price),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateSellPlot(SinglePlot $plot, int $price) : \Generator{
		if($this->economyProvider === null or $price <= 0){
			return false;
		}

		$newPlot = clone $plot;
		$newPlot->price = $price;
		$ev = new MyPlotSettingEvent($plot, $newPlot);
		$ev->call();
		if($ev->isCancelled())
			return false;
		$plot = $ev->getPlot();
		return yield from $this->dataProvider->savePlot($plot);
	}

	/**
	 * @param SinglePlot    $plot
	 * @param Player        $player
	 * @param callable|null $onComplete
	 * @phpstan-param (callable(bool): void)|null       $onComplete
	 * @param callable|null $onFail
	 * @phpstan-param (callable(\Throwable): void)|null $catches
	 */
	public function buyPlot(SinglePlot $plot, Player $player, ?callable $onComplete = null, ?callable $onFail = null) : void{
		Await::g2c(
			$this->generateBuyPlot($plot, $player),
			$onComplete,
			$onFail === null ? [] : [$onFail]
		);
	}

	public function generateBuyPlot(SinglePlot $plot, Player $player) : \Generator{
		if($this->economyProvider === null){
			return false;
		}
		if(!yield from $this->economyProvider->reduceMoney($player, $plot->price)){
			return false;
		}
		if(!yield from $this->economyProvider->addMoney($this->plugin->getServer()->getOfflinePlayer($plot->owner), $plot->price)){
			yield from $this->economyProvider->addMoney($player, $plot->price);
			return false;
		}

		return yield from $this->generateClaimPlot($plot, $player->getName(), '');
	}

	/**
	 * @param BasePlot $plot
	 *
	 * @return array
	 * @phpstan-return array<array<int|Chunk|null>>
	 */
	public function getPlotChunks(BasePlot $plot) : array{
		$plotLevel = $this->getLevelSettings($plot->levelName);
		if($plotLevel === null)
			return [];

		$aabb = $this->getPlotBB($plot);
		$xMin = $aabb->minX >> Chunk::COORD_MASK;
		$zMin = $aabb->minZ >> Chunk::COORD_MASK;
		$xMax = $aabb->maxX >> Chunk::COORD_MASK;
		$zMax = $aabb->maxZ >> Chunk::COORD_MASK;

		$level = $this->plugin->getServer()->getWorldManager()->getWorldByName($plot->levelName);
		$chunks = [];
		for($x = $xMin; $x <= $xMax; ++$x){
			for($z = $zMin; $z <= $zMax; ++$z){
				$chunks[] = [$x, $z, $level->getChunk($x, $z)];
			}
		}
		return $chunks;
	}

	public function onDisable() : void{
		$this->dataProvider->close();
	}
}