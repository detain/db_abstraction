PRAGMA synchronous = OFF;
PRAGMA journal_mode = MEMORY;
BEGIN TRANSACTION;
CREATE TABLE `service_types` (
  `st_id` integer  NOT NULL PRIMARY KEY AUTOINCREMENT
,  `st_name` varchar(50) NOT NULL
,  `st_category` integer  NOT NULL
,  `st_module` varchar(30) NOT NULL
);
INSERT INTO `service_types` VALUES (1,'KVM Windows',2,'vps');
INSERT INTO `service_types` VALUES (2,'KVM Linux',2,'vps');
INSERT INTO `service_types` VALUES (3,'Cloud KVM Windows',3,'vps');
INSERT INTO `service_types` VALUES (4,'Cloud KVM Linux',3,'vps');
INSERT INTO `service_types` VALUES (5,'SSD OpenVZ',1,'vps');
INSERT INTO `service_types` VALUES (6,'OpenVZ',1,'vps');
INSERT INTO `service_types` VALUES (7,'Xen Windows',3,'vps');
INSERT INTO `service_types` VALUES (8,'Xen Linux',3,'vps');
INSERT INTO `service_types` VALUES (9,'LXC',4,'vps');
INSERT INTO `service_types` VALUES (10,'VMware',5,'vps');
INSERT INTO `service_types` VALUES (11,'Hyper-V',6,'vps');
INSERT INTO `service_types` VALUES (12,'Virtuozzo 7',7,'vps');
INSERT INTO `service_types` VALUES (13,'SSD Virtuozzo 7',7,'vps');
INSERT INTO `service_types` VALUES (100,'OpenSRS',100,'domains');
INSERT INTO `service_types` VALUES (200,'cPanel/WHM',200,'webhosting');
INSERT INTO `service_types` VALUES (201,'VestaCP',201,'webhosting');
INSERT INTO `service_types` VALUES (202,'Parallels Plesk',202,'webhosting');
INSERT INTO `service_types` VALUES (203,'Parallels Plesk Automation',203,'webhosting');
INSERT INTO `service_types` VALUES (204,'WordPress Managed cPanel',200,'webhosting');
INSERT INTO `service_types` VALUES (205,'7-Day cPanel Demo Server',200,'webhosting');
INSERT INTO `service_types` VALUES (300,'GlobalSign SSL',300,'ssl');
INSERT INTO `service_types` VALUES (400,'Raid Backups',400,'backups');
INSERT INTO `service_types` VALUES (401,'SWIFT Storage Backup',401,'backups');
INSERT INTO `service_types` VALUES (402,'Gluster Storage Backup',402,'backups');
INSERT INTO `service_types` VALUES (403,'DRBL Storage Backup',403,'backups');
INSERT INTO `service_types` VALUES (404,'Raid Storage Backup',404,'backups');
INSERT INTO `service_types` VALUES (500,'CPanel',500,'licenses');
INSERT INTO `service_types` VALUES (501,'Fantastico',501,'licenses');
INSERT INTO `service_types` VALUES (502,'LiteSpeed',502,'licenses');
INSERT INTO `service_types` VALUES (503,'Softaculous',503,'licenses');
INSERT INTO `service_types` VALUES (504,'WHMSonic',504,'licenses');
INSERT INTO `service_types` VALUES (505,'KSplice',505,'licenses');
INSERT INTO `service_types` VALUES (506,'DirectAdmin',506,'licenses');
INSERT INTO `service_types` VALUES (507,'Parallells',507,'licenses');
INSERT INTO `service_types` VALUES (508,'CloudLinux',508,'licenses');
INSERT INTO `service_types` VALUES (509,'Webuzo',509,'licenses');
INSERT INTO `service_types` VALUES (600,'Dedicated Server',600,'servers');
CREATE INDEX "idx_service_types_st_category_FK_idx" ON "service_types" (`st_category`);
END TRANSACTION;
