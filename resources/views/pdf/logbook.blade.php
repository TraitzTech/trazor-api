<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Internship Log Book</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
            line-height: 1.4;
        }

        .header-title {
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 20px;
            text-decoration: underline;
        }


        .form-line strong {
            font-weight: bold;
        }

        .underline {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 200px;
            margin-left: 5px;
        }

        .short-underline {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 100px;
            margin-left: 5px;
        }

        .section-header {
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            text-align: center;
            font-size: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border: 2px solid #000;
        }

        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }

        th {
            background-color: #f8f8f8;
            font-weight: bold;
        }

        .day-cell {
            width: 20%;
            font-weight: bold;
        }

        .activity-cell {
            width: 80%;
            min-height: 40px;
        }

        .remarks-section {
            margin-top: 20px;
        }

        .remarks-box {
            border: 1px solid #000;
            min-height: 80px;
            padding: 8px;
            margin-top: 10px;
        }

        .signature-section {
            margin-top: 30px;
        }

        .signature-line {
            margin-bottom: 15px;
        }

        .row {
            display: flex;
            flex-direction: row;
            margin-bottom: 6px;
            gap: 10px;
            align-items: center; /* Aligns NAME and value vertically */
        }

        .form-line {
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            margin-bottom: 12px;
            font-size: 13px;
            flex: 1;
            min-width: 200px;
        }


    </style>
</head>
<body>

<div class="header-title">STUDENT INTERNSHIP LOG BOOK</div>

<div class="form-line">
    <strong>NAME:</strong> {{ $student['name'] }}
</div>

<div class="form-line">
    <strong>REGISTRATION NUMBER:</strong> {{ $student['matric_number'] }} <strong style="margin-left: 100px;">/ LEVEL:</strong> {{ $student['level'] }}
</div>

<div class="form-line">
    <strong>DEPARTMENT:</strong> {{ $student['department'] }} <strong style="margin-left: 100px;">/ OPTION:</strong> {{ $student['option'] }}
</div>

<div class="form-line">
    <strong>ATTACHMENT PERIOD:</strong> From {{ $period_from }} to {{ $period_to }}
</div>

<br>
<br>
<div class="form-line">
    <strong>COMPANY'S NAME:</strong> Traitz Tech Co Ltd
</div>

<div class="form-line">
    <strong>ADDRESS:</strong> ENS Street Bambili
</div>

<div class="form-line">
    <strong>PHONE NUMBER:</strong> +237 677802114 <strong style="margin-left: 100px;">/ E-MAIL:</strong> info@traitz.tech
</div>

<div class="form-line">
    <strong>DEPARTMENT/DIVISION:</strong> Mezam Division
</div>

<div class="section-header">DAILY ATTACHMENT RECORDS</div>

<div style="margin-bottom: 10px;"><strong>Week {{ $week }}</strong></div>

<table>
    <thead>
        <tr>
            <th class="day-cell">Day / Date</th>
            <th class="activity-cell">Day's Activities</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($entries as $day => $text)
        <tr>
            <td class="day-cell"><strong>{{ ucfirst($day) }}</strong></td>
            <td class="activity-cell">{{ $text }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<div class="remarks-section">
    <div
        style="border: 1px solid #000;
            min-height: 6px;
            padding: 4px; font-size:12px;" class="section-header">INTERN'S REMARKS</div>
    <div class="remarks-box">{{ $remarks }}</div>
</div>

<div style="margin-top: 15px; font-size: 11px;">
    <strong>Further helpful Information like Drawings, Diagrams, Sketches, Calculations, Notes, etc. can be done on extra A4 sheets and attached to this record sheet.</strong>
</div>

<div class="signature-section">
    <div class="signature-line">
        <strong>Intern's Signature:</strong> <span class="underline"></span>
    </div>

    <div class="signature-line">
        <strong>Field Supervisor's Signature:</strong> <span class="underline"></span>
    </div>

    <div class="signature-line">
        <strong>Name:</strong> <span class="underline"></span>
    </div>

    <div class="signature-line">
        <strong>Company's Stamp:</strong> <span class="underline"></span>
    </div>

    <div class="signature-line">
        <strong>Date:</strong> <span class="underline"></span>
    </div>
</div>

</body>
</html>
