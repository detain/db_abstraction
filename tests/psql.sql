-- MySQL dump 10.13  Distrib 5.7.22, for Linux (x86_64)
--
-- Host: localhost    Database: tests
-- ------------------------------------------------------
-- Server version	5.7.22-0ubuntu18.04.1
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO,POSTGRESQL' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table "service_types"
--

DROP TABLE IF EXISTS "service_types";
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE "service_types" (
  "st_id" int(11) unsigned NOT NULL COMMENT 'The Service Type ID',
  "st_name" varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Service Type Name',
  "st_category" int(10) unsigned NOT NULL,
  "st_module" varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The Module this service type is for',
  PRIMARY KEY ("st_id"),
  KEY "st_category_FK_idx" ("st_category"),
  CONSTRAINT "st_category_FK" FOREIGN KEY ("st_category") REFERENCES "service_categories" ("category_id") ON DELETE CASCADE ON UPDATE CASCADE
);
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table "service_types"
--

LOCK TABLES "service_types" WRITE;
/*!40000 ALTER TABLE "service_types" DISABLE KEYS */;
INSERT INTO "service_types" VALUES (1,'KVM Windows',2,'vps'),(2,'KVM Linux',2,'vps'),(3,'Cloud KVM Windows',3,'vps'),(4,'Cloud KVM Linux',3,'vps'),(5,'SSD OpenVZ',1,'vps'),(6,'OpenVZ',1,'vps'),(7,'Xen Windows',3,'vps'),(8,'Xen Linux',3,'vps'),(9,'LXC',4,'vps'),(10,'VMware',5,'vps'),(11,'Hyper-V',6,'vps'),(12,'Virtuozzo 7',7,'vps'),(13,'SSD Virtuozzo 7',7,'vps'),(100,'OpenSRS',100,'domains'),(200,'cPanel/WHM',200,'webhosting'),(201,'VestaCP',201,'webhosting'),(202,'Parallels Plesk',202,'webhosting'),(203,'Parallels Plesk Automation',203,'webhosting'),(204,'WordPress Managed cPanel',200,'webhosting'),(205,'7-Day cPanel Demo Server',200,'webhosting'),(300,'GlobalSign SSL',300,'ssl'),(400,'Raid Backups',400,'backups'),(401,'SWIFT Storage Backup',401,'backups'),(402,'Gluster Storage Backup',402,'backups'),(403,'DRBL Storage Backup',403,'backups'),(404,'Raid Storage Backup',404,'backups'),(500,'CPanel',500,'licenses'),(501,'Fantastico',501,'licenses'),(502,'LiteSpeed',502,'licenses'),(503,'Softaculous',503,'licenses'),(504,'WHMSonic',504,'licenses'),(505,'KSplice',505,'licenses'),(506,'DirectAdmin',506,'licenses'),(507,'Parallells',507,'licenses'),(508,'CloudLinux',508,'licenses'),(509,'Webuzo',509,'licenses'),(600,'Dedicated Server',600,'servers');
/*!40000 ALTER TABLE "service_types" ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2018-07-16 15:08:40
