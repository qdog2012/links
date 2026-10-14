CREATE TABLE IF NOT EXISTS users (
 id VARCHAR(32) PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL, password VARCHAR(255) NOT NULL,
 role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'editor', active TINYINT(1) NOT NULL DEFAULT 1,
 created_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE IF NOT EXISTS projects (
 id VARCHAR(32) PRIMARY KEY, owner_id VARCHAR(32) NOT NULL,
 name VARCHAR(80) NOT NULL, description TEXT NOT NULL,
 created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id), INDEX(owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS project_members (
 project_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL,
 role ENUM('admin','editor','viewer') NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_at VARCHAR(40) NOT NULL, PRIMARY KEY(project_id,user_id),
 FOREIGN KEY(project_id) REFERENCES projects(id), FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sessions (
 token CHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL, expires BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS entities (
 id VARCHAR(32) PRIMARY KEY, project_id VARCHAR(32) NOT NULL,
 body JSON NOT NULL, version INT NOT NULL DEFAULT 1,
 FOREIGN KEY(project_id) REFERENCES projects(id), UNIQUE(project_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS relations (
 id VARCHAR(32) PRIMARY KEY, project_id VARCHAR(32) NOT NULL,
 source VARCHAR(32) NOT NULL, target VARCHAR(32) NOT NULL,
 body JSON NOT NULL, version INT NOT NULL DEFAULT 1,
 FOREIGN KEY(project_id) REFERENCES projects(id),
 FOREIGN KEY(project_id,source) REFERENCES entities(project_id,id),
 FOREIGN KEY(project_id,target) REFERENCES entities(project_id,id), INDEX(project_id,target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id VARCHAR(32) NOT NULL,
 at VARCHAR(40) NOT NULL, actor_id VARCHAR(32) NOT NULL, actor VARCHAR(80) NOT NULL,
 action VARCHAR(40) NOT NULL, kind VARCHAR(40) NOT NULL,
 object_id VARCHAR(32), label VARCHAR(180), before_json JSON, after_json JSON,
 FOREIGN KEY(project_id) REFERENCES projects(id), INDEX(project_id,object_id), INDEX(project_id,at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS settings (
 `key` VARCHAR(40) PRIMARY KEY, value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
