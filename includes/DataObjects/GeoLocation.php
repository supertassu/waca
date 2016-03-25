<?php
namespace Waca\DataObjects;

use Exception;
use Waca\DataObject;
use Waca\Exceptions\OptimisticLockFailedException;
use Waca\PdoDatabase;

/**
 * Geolocation data object
 *
 * This is a cache.
 */
class GeoLocation extends DataObject
{
	private $address;
	private $data;
	private $creation;

	/**
	 * @param string      $address
	 * @param PdoDatabase $database
	 *
	 * @return GeoLocation
	 */
	public static function getByAddress($address, PdoDatabase $database)
	{
		$statement = $database->prepare("SELECT * FROM geolocation WHERE address = :id LIMIT 1;");
		$statement->bindValue(":id", $address);

		$statement->execute();

		$resultObject = $statement->fetchObject(get_called_class());

		if ($resultObject != false) {
			$resultObject->isNew = false;
			$resultObject->setDatabase($database);
		}

		return $resultObject;
	}

	public function save()
	{
		if ($this->isNew()) {
			// insert
			$statement = $this->dbObject->prepare(<<<SQL
INSERT INTO `geolocation` (address, data) VALUES (:address, :data);
SQL
			);
			$statement->bindValue(":address", $this->address);
			$statement->bindValue(":data", $this->data);

			if ($statement->execute()) {
				$this->id = $this->dbObject->lastInsertId();
			}
			else {
				throw new Exception($statement->errorInfo());
			}
		}
		else {
			// update
			$statement = $this->dbObject->prepare(<<<SQL
UPDATE `geolocation`
SET address = :address, data = :data, updateversion = updateversion + 1
WHERE id = :id AND updateversion = :updateversion
LIMIT 1;
SQL
			);

			$statement->bindValue(":id", $this->id);
			$statement->bindValue(":updateversion", $this->updateversion);

			$statement->bindValue(":address", $this->address);
			$statement->bindValue(":data", $this->data);

			if (!$statement->execute()) {
				throw new Exception($statement->errorInfo());
			}

			if ($statement->rowCount() !== 1) {
				throw new OptimisticLockFailedException();
			}

			$this->updateversion++;
		}
	}

	public function getAddress()
	{
		return $this->address;
	}

	/**
	 * @param string $address
	 */
	public function setAddress($address)
	{
		$this->address = $address;
	}

	public function getData()
	{
		return unserialize($this->data);
	}

	public function setData($data)
	{
		$this->data = serialize($data);
	}

	public function getCreation()
	{
		return $this->creation;
	}
}
