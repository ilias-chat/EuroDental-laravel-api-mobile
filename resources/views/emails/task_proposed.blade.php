<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
        }
        .container {
            background-color: #ffffff;
            padding: 20px;
        }
        h2 {
            color: #333;
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: normal;
        }
        .urgent {
            color: #ef4444;
            font-weight: bold;
        }
        p {
            margin: 0 0 20px 0;
            color: #555;
        }
        .task-info {
            margin: 20px 0;
            padding: 15px 0;
            border-top: 1px solid #e0e0e0;
            border-bottom: 1px solid #e0e0e0;
        }
        .task-name {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }
        .info-row {
            margin: 8px 0;
            font-size: 14px;
            color: #555;
        }
        .footer {
            margin-top: 30px;
            color: #999;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Nouvelle proposition de tâche
            @if($urgent)
                <span class="urgent">(URGENT)</span>
            @endif
        </h2>

        <p><strong>{{ $proposerName }}</strong> a proposé une nouvelle tâche qui nécessite votre attention.</p>

        <div class="task-info">
            <div class="task-name">{{ $taskName }}</div>

            <div class="info-row"><strong>Type :</strong> {{ $taskType }}</div>

            @if($clientName)
            <div class="info-row"><strong>Client :</strong> {{ $clientName }}</div>
            @endif

            <div class="info-row"><strong>Proposé par :</strong> {{ $proposerName }}</div>

            @if($description)
            <div class="info-row" style="margin-top: 12px;">
                <strong>Description :</strong><br>
                {{ $description }}
            </div>
            @endif
        </div>

        <p>Veuillez consulter le panneau d'administration pour approuver ou rejeter cette proposition.</p>

        <div class="footer">
            © {{ date('Y') }} EuroDental
        </div>
    </div>
</body>
</html>
