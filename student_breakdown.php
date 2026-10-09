<?php
require_once __DIR__ . '/config.php';
$db = db();

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $course = isset($_GET['course']) ? $_GET['course'] : '';
    $gender = isset($_GET['gender']) ? $_GET['gender'] : '';
    
    // Get students by section, sorted alphabetically
    $query = "SELECT name, student_id, course, year_level, section, gender, department 
              FROM students";
    
    $whereConditions = [];
    
    if ($course && in_array($course, ['BEED', 'BSED', 'BTLED', 'BIT', 'BSIT'])) {
        $whereConditions[] = "course = '" . $db->real_escape_string($course) . "'";
    }
    
    if ($gender && in_array($gender, ['Male', 'Female'])) {
        $whereConditions[] = "gender = '" . $db->real_escape_string($gender) . "'";
    }
    
    if (!empty($whereConditions)) {
        $query .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    $query .= " ORDER BY section ASC, name ASC";
    
    $result = $db->query($query);
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    // Generate CSV
    $filename = ($course ? $course . '-' : 'All-Students-');
    if ($gender) {
        $filename .= $gender . '-';
    }
    $filename .= date('Y-m-d-His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    fputcsv($output, ['Name', 'Student ID', 'Course', 'Year Level', 'Section', 'Gender', 'Department']);
    
    foreach ($students as $student) {
        fputcsv($output, [
            $student['name'],
            $student['student_id'],
            $student['course'],
            $student['year_level'],
            $student['section'],
            $student['gender'],
            $student['department']
        ]);
    }
    
    fclose($output);
    exit;
}

// Query to get student count by course
$courses = ['BEED', 'BSED', 'BTLED', 'BIT', 'BSIT'];
$breakdown = [];
$totalStudents = 0;

foreach ($courses as $course) {
    $result = $db->query("SELECT COUNT(*) as count FROM students WHERE course = '" . $db->real_escape_string($course) . "'");
    $row = $result->fetch_assoc();
    $count = $row['count'] ?? 0;
    $breakdown[$course] = $count;
    $totalStudents += $count;
}

// Also get department breakdown
$deptQuery = $db->query("SELECT department, COUNT(*) as count FROM students GROUP BY department");
$deptBreakdown = [];
while ($row = $deptQuery->fetch_assoc()) {
    $deptBreakdown[$row['department']] = $row['count'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Student Breakdown by Course</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
        }
        h2 {
            color: #666;
            margin-top: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #007bff;
            color: white;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
        .total-row {
            font-weight: bold;
            background-color: #e8f4f8;
        }
        .chart {
            margin: 20px 0;
        }
        .bar {
            display: flex;
            align-items: center;
            margin: 10px 0;
        }
        .bar-label {
            width: 80px;
            font-weight: bold;
        }
        .bar-content {
            display: flex;
            align-items: center;
            flex: 1;
        }
        .bar-fill {
            height: 30px;
            background: linear-gradient(90deg, #007bff, #0056b3);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 10px;
            color: white;
            font-weight: bold;
            min-width: 50px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Student Breakdown by Course</h1>
        
        <div style="margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="?export=csv" style="padding: 12px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; cursor: pointer; display: inline-block; transition: opacity 0.3s;">
                📥 Export All Students (CSV)
            </a>
            <div style="position: relative; display: inline-block;">
                <button id="exportCourseBtn" style="padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; transition: opacity 0.3s;">
                    📥 Export by Course
                </button>
                <div id="courseDropdown" style="display: none; position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 10;">
                    <a href="?export=csv&course=BEED" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BEED - Export</a>
                    <a href="?export=csv&course=BSED" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BSED - Export</a>
                    <a href="?export=csv&course=BTLED" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BTLED - Export</a>
                    <a href="?export=csv&course=BIT" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BIT - Export</a>
                    <a href="?export=csv&course=BSIT" style="display: block; padding: 10px 20px; color: #333; text-decoration: none;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BSIT - Export</a>
                </div>
            </div>
            <div style="position: relative; display: inline-block;">
                <button id="exportMaleBtn" style="padding: 12px 20px; background: #17a2b8; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; transition: opacity 0.3s;">
                    👨 Export Male Students
                </button>
                <div id="maleDropdown" style="display: none; position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 10;">
                    <a href="?export=csv&gender=Male" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">All Courses - Male</a>
                    <a href="?export=csv&course=BEED&gender=Male" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BEED - Male</a>
                    <a href="?export=csv&course=BSED&gender=Male" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BSED - Male</a>
                    <a href="?export=csv&course=BTLED&gender=Male" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BTLED - Male</a>
                    <a href="?export=csv&course=BIT&gender=Male" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BIT - Male</a>
                    <a href="?export=csv&course=BSIT&gender=Male" style="display: block; padding: 10px 20px; color: #333; text-decoration: none;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BSIT - Male</a>
                </div>
            </div>
            <div style="position: relative; display: inline-block;">
                <button id="exportFemaleBtn" style="padding: 12px 20px; background: #e83e8c; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; transition: opacity 0.3s;">
                    👩 Export Female Students
                </button>
                <div id="femaleDropdown" style="display: none; position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 10;">
                    <a href="?export=csv&gender=Female" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">All Courses - Female</a>
                    <a href="?export=csv&course=BEED&gender=Female" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BEED - Female</a>
                    <a href="?export=csv&course=BSED&gender=Female" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BSED - Female</a>
                    <a href="?export=csv&course=BTLED&gender=Female" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BTLED - Female</a>
                    <a href="?export=csv&course=BIT&gender=Female" style="display: block; padding: 10px 20px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BIT - Female</a>
                    <a href="?export=csv&course=BSIT&gender=Female" style="display: block; padding: 10px 20px; color: #333; text-decoration: none;" onmouseover="this.style.backgroundColor='#f0f0f0'" onmouseout="this.style.backgroundColor='white'">BSIT - Female</a>
                </div>
            </div>
        </div>
        
        <script>
            document.getElementById('exportCourseBtn').addEventListener('click', function() {
                const dropdown = document.getElementById('courseDropdown');
                dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
            });
            
            document.getElementById('exportMaleBtn').addEventListener('click', function() {
                const dropdown = document.getElementById('maleDropdown');
                dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
            });
            
            document.getElementById('exportFemaleBtn').addEventListener('click', function() {
                const dropdown = document.getElementById('femaleDropdown');
                dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
            });
            
            document.addEventListener('click', function(event) {
                const coursBtn = document.getElementById('exportCourseBtn');
                const courseDropdown = document.getElementById('courseDropdown');
                const maleBtn = document.getElementById('exportMaleBtn');
                const maleDropdown = document.getElementById('maleDropdown');
                const femaleBtn = document.getElementById('exportFemaleBtn');
                const femaleDropdown = document.getElementById('femaleDropdown');
                
                if (!coursBtn.contains(event.target) && !courseDropdown.contains(event.target)) {
                    courseDropdown.style.display = 'none';
                }
                if (!maleBtn.contains(event.target) && !maleDropdown.contains(event.target)) {
                    maleDropdown.style.display = 'none';
                }
                if (!femaleBtn.contains(event.target) && !femaleDropdown.contains(event.target)) {
                    femaleDropdown.style.display = 'none';
                }
            });
        </script>
        
        <h2>Students per Course</h2>
        <table>
            <thead>
                <tr>
                    <th>Course Code</th>
                    <th>Course Name</th>
                    <th>Number of Students</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $courseNames = [
                    'BEED' => 'Bachelor of Elementary Education',
                    'BSED' => 'Bachelor of Science in Education',
                    'BTLED' => 'Bachelor of Technology and Livelihood Education',
                    'BIT' => 'Bachelor of Information Technology',
                    'BSIT' => 'Bachelor of Science in Information Technology'
                ];
                
                foreach ($courses as $course):
                    $count = $breakdown[$course];
                    $percentage = $totalStudents > 0 ? round(($count / $totalStudents) * 100, 2) : 0;
                ?>
                <tr>
                    <td><strong><?php echo $course; ?></strong></td>
                    <td><?php echo $courseNames[$course]; ?></td>
                    <td><?php echo $count; ?></td>
                    <td><?php echo $percentage; ?>%</td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">TOTAL</td>
                    <td><?php echo $totalStudents; ?></td>
                    <td>100%</td>
                </tr>
            </tbody>
        </table>

        <h2>Visual Distribution by Course</h2>
        <div class="chart">
            <?php
            $maxCount = max($breakdown);
            foreach ($courses as $course):
                $count = $breakdown[$course];
                $width = $maxCount > 0 ? ($count / $maxCount) * 100 : 0;
            ?>
            <div class="bar">
                <div class="bar-label"><?php echo $course; ?></div>
                <div class="bar-content">
                    <div class="bar-fill" style="width: <?php echo max(50, $width); ?>%;">
                        <?php echo $count; ?> students
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h2>Breakdown by Department</h2>
        <table>
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Number of Students</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($deptBreakdown as $dept => $count):
                    $percentage = $totalStudents > 0 ? round(($count / $totalStudents) * 100, 2) : 0;
                ?>
                <tr>
                    <td><strong><?php echo $dept; ?></strong></td>
                    <td><?php echo $count; ?></td>
                    <td><?php echo $percentage; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 30px; padding: 20px; background: #e8f4f8; border-radius: 4px;">
            <h3>📈 Summary Statistics</h3>
            <ul>
                <li><strong>Total Students:</strong> <?php echo $totalStudents; ?></li>
                <li><strong>Number of Programs:</strong> <?php echo count($courses); ?></li>
                <li><strong>Departments:</strong> <?php echo count($deptBreakdown); ?></li>
                <li><strong>Average Students per Course:</strong> <?php echo $totalStudents > 0 ? round($totalStudents / count($courses), 2) : 0; ?></li>
            </ul>
        </div>
    </div>
</body>
</html>
