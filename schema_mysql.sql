-- DudeWeb schéma (MySQL / MariaDB)
CREATE TABLE IF NOT EXISTS maps(
  id BIGINT PRIMARY KEY, name VARCHAR(255), image_id BIGINT, canvas_id BIGINT, sort_order INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS device_types(
  id BIGINT PRIMARY KEY, name VARCHAR(255), icon VARCHAR(128)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS devices(
  id BIGINT PRIMARY KEY, name VARCHAR(255), ip VARCHAR(64), dns VARCHAR(255),
  snmp_profile BIGINT, username VARCHAR(128), password VARCHAR(255), type_id BIGINT,
  status VARCHAR(16) DEFAULT 'unknown', last_check DATETIME NULL, rtt DOUBLE NULL, down_since DATETIME NULL, monitored TINYINT DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS map_nodes(
  id BIGINT PRIMARY KEY, map_id BIGINT, kind VARCHAR(16) DEFAULT 'device',
  device_id BIGINT, submap_id BIGINT, x INT, y INT, image BIGINT, label TEXT, INDEX(map_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS map_links(
  id BIGINT PRIMARY KEY, map_id BIGINT, from_node BIGINT,
  to_node BIGINT, width INT, style INT, thickness INT, ltype VARCHAR(64), snmp_device BIGINT, snmp_ifindex INT, snmp_type INT, label TEXT, INDEX(map_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS services(
  id BIGINT PRIMARY KEY, device_id BIGINT, name VARCHAR(128),
  probe_id BIGINT, ptype VARCHAR(16), port INT, enabled INT, down INT, acked INT,
  status VARCHAR(16) DEFAULT 'unknown', last_check DATETIME NULL, INDEX(device_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS status_history(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, device_id BIGINT,
  ts DATETIME, status VARCHAR(16), rtt DOUBLE, INDEX(device_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS events(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, ts DATETIME, device_id BIGINT,
  device_name VARCHAR(255), ip VARCHAR(64), status VARCHAR(16), message TEXT, INDEX(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS probes(
  id BIGINT PRIMARY KEY, name VARCHAR(64), type VARCHAR(16), port INT, dns_name VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS outages(
  id BIGINT PRIMARY KEY AUTO_INCREMENT, device_id BIGINT, service VARCHAR(64), started DATETIME, ended DATETIME NULL, duration INT, INDEX(device_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS link_types(id BIGINT PRIMARY KEY, name VARCHAR(64), style INT, thickness INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS snmp_profiles(id BIGINT PRIMARY KEY, name VARCHAR(64), community VARCHAR(64), version INT, port INT, sec_name VARCHAR(128), auth_pass VARCHAR(128), priv_pass VARCHAR(128), auth_proto VARCHAR(8), priv_proto VARCHAR(8)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS link_traffic(link_id BIGINT PRIMARY KEY, in_oct BIGINT, out_oct BIGINT, ts DATETIME, rx_bps DOUBLE, tx_bps DOUBLE, speed_bps DOUBLE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
