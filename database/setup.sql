-- =======================================================
-- รันโค้ดชุดนี้ในฐานข้อมูล s68160380 ได้ทันที (ตัดคำสั่ง CREATE DATABASE ออกแล้ว)
-- =======================================================

-- 1. ตารางผู้ใช้งาน
CREATE TABLE IF NOT EXISTS Users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    points INT DEFAULT 20, -- แต้มเริ่มต้นตอนสมัคร
    trust_score DECIMAL(3,2) DEFAULT 5.00, -- คะแนนรีวิวเต็ม 5
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. ตารางคลังหนังสือมาตรฐาน (Master Data)
CREATE TABLE IF NOT EXISTS Books_Master (
    book_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    book_value_points INT DEFAULT 20 -- มูลค่าแต้มมาตรฐานของเล่มนี้
) ENGINE=InnoDB;

-- 3. ตารางหนังสือจริงของผู้ใช้งานแต่ละคน
CREATE TABLE IF NOT EXISTS User_Books (
    user_book_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    book_condition ENUM('ใหม่มาก', 'ปานกลาง', 'เก่า') NOT NULL,
    status ENUM('พร้อมแลก', 'อยู่ระหว่างดีล', 'แลกสำเร็จแล้ว') DEFAULT 'พร้อมแลก',
    photo_url VARCHAR(255) NULL,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES Books_Master(book_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. ตารางการจับคู่แลกเปลี่ยน (ธุรกรรมส่วนกลาง)
CREATE TABLE IF NOT EXISTS Swaps (
    swap_id INT AUTO_INCREMENT PRIMARY KEY,
    requester_id INT NOT NULL, -- คนกดขอแลก
    receiver_id INT NOT NULL,  -- เจ้าของหนังสือ
    requested_book_id INT NOT NULL, -- เล่มที่อยากได้จากเขา
    swap_status ENUM('รอดำเนินการ', 'ยอมรับแล้ว', 'ปฏิเสธ', 'ส่งของแล้ว', 'สำเร็จ', 'มีข้อพิพาท') DEFAULT 'รอดำเนินการ',
    tracking_number VARCHAR(50) NULL, -- เลขพัสดุ
    receipt_photo_url VARCHAR(255) NULL, -- รูปใบเสร็จพัสดุ
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES Users(user_id),
    FOREIGN KEY (receiver_id) REFERENCES Users(user_id),
    FOREIGN KEY (requested_book_id) REFERENCES User_Books(user_book_id)
) ENGINE=InnoDB;

-- 5. ตารางประวัติธุรกรรมแต้ม (Log กันโกง ย้อนดูสเตทเม้นท์ได้)
CREATE TABLE IF NOT EXISTS Point_Transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount INT NOT NULL, -- ติดลบคือจ่าย/โดนล็อก เป็นบวกคือได้แต้ม
    transaction_type ENUM('สมัครสมาชิก', 'ลงทะเบียนหนังสือ', 'ล็อกมัดจำ', 'คืนมัดจำ', 'ได้รับแต้มสำเร็จ') NOT NULL,
    reference_id INT NULL, -- เอาไว้เชื่อมกับ swap_id
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. ตารางรีวิวผู้ใช้งาน (ระบบดาวสกรีนคนโกง)
CREATE TABLE IF NOT EXISTS User_Reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    swap_id INT NOT NULL,
    reviewer_id INT NOT NULL,   -- คนให้คะแนน
    reviewed_user_id INT NOT NULL, -- คนที่ถูกรีวิว
    rating INT CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (swap_id) REFERENCES Swaps(swap_id),
    FOREIGN KEY (reviewer_id) REFERENCES Users(user_id),
    FOREIGN KEY (reviewed_user_id) REFERENCES Users(user_id)
) ENGINE=InnoDB;

-- แทรกข้อมูลตัวอย่างสมมติสำหรับการทดสอบหน้าเว็บ
INSERT INTO Users (username, email, password_hash, points) VALUES 
('Somchai', 'somchai@email.com', 'hash123', 50),
('Somsri', 'somsri@email.com', 'hash456', 30)
ON DUPLICATE KEY UPDATE username=username;

INSERT INTO Books_Master (title, author, category, book_value_points) VALUES 
('คิดแบบยักษ์ใหญ่', 'John C. Maxwell', 'พัฒนาตนเอง', 40),
('คินดะอิจิยอดนักสืบ', 'Seishi Yokomizo', 'นิยายสืบสวน', 30)
ON DUPLICATE KEY UPDATE title=title;

INSERT INTO User_Books (user_id, book_id, book_condition, status) VALUES 
(2, 1, 'ใหม่มาก', 'พร้อมแลก')
ON DUPLICATE KEY UPDATE user_id=user_id;
