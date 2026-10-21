/* ============================================================
   #1 STUDENTS TABLE
   ============================================================ */

CREATE TABLE IF NOT EXISTS students (
    student_id INT PRIMARY KEY AUTO_INCREMENT,
    student_first_name VARCHAR(50) NOT NULL,
    student_last_name VARCHAR(50) NOT NULL,
    student_course VARCHAR(50) NOT NULL,
    student_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


/* ============================================================
   #2 BOOKS TABLE
   ============================================================ */

CREATE TABLE IF NOT EXISTS books (
    book_id INT PRIMARY KEY AUTO_INCREMENT,
    book_title VARCHAR(100) NOT NULL,
    book_author VARCHAR(100) NOT NULL,
    book_category VARCHAR(50) NOT NULL,
    book_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


/* ============================================================
   #3 BORROW TABLE
   ============================================================ */

CREATE TABLE IF NOT EXISTS borrow (
    borrow_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    book_id INT NOT NULL,
    borrow_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    borrow_return_date TIMESTAMP NULL DEFAULT NULL,

    CONSTRAINT fk_borrow_student
        FOREIGN KEY (student_id)
        REFERENCES students(student_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_borrow_book
        FOREIGN KEY (book_id)
        REFERENCES books(book_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;


/* ============================================================
   #4 INSERT STUDENTS
   ============================================================ */

INSERT INTO students (
    student_first_name,
    student_last_name,
    student_course
) VALUES
('CLIFF AMADEUS', 'EVANGELIO', 'BSIT'),
('JAN XAVIER', 'EVANGELIO', 'BSA-AGRONOMY'),
('RON EDMUND', 'EVANGELIO', 'BSEE'),
('RK', 'FERNANDEZ', 'BSIT');


/* ============================================================
   #5 INSERT BOOKS
   ============================================================ */

INSERT INTO books (
    book_title,
    book_author,
    book_category
) VALUES
('Project Hail Mary', 'Andy Weir', 'Science Fiction'),
('Jurassic Park', 'Michael Crichton', 'Science Fiction'),
('1984', 'George Orwell', 'Science Fiction');


/* ============================================================
   #6 INSERT BORROW RECORDS
   ============================================================ */

INSERT INTO borrow (
    student_id,
    book_id
) VALUES
(1, 2),
(2, 1),
(3, 3);


/* ============================================================
   END OF SQL SCRIPT
   ============================================================ */
