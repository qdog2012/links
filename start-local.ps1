param([int]$Port=8765)
$ErrorActionPreference='Stop'
$taskAppPath=$PSScriptRoot
$taskRootPath=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$taskRuntimePath=Join-Path $taskRootPath 'work'
New-Item -ItemType Directory -Path $taskRuntimePath -Force | Out-Null
function Test-TaskPort([string]$Address,[int]$Number) {
  $taskClient=[System.Net.Sockets.TcpClient]::new()
  try {$taskConnect=$taskClient.ConnectAsync($Address,$Number);if($taskConnect.Wait(500)) {return $taskClient.Connected};return $false} catch{return $false} finally{$taskClient.Dispose()}
}
if(!(Test-Path -LiteralPath (Join-Path $taskAppPath 'backend\.env'))) {throw 'Configure backend/.env first; see README.md.'}
$taskSettings=@{}
Get-Content -LiteralPath (Join-Path $taskAppPath 'backend\.env') | ForEach-Object {if($_ -match '^([^#=]+)=(.*)$'){$taskSettings[$matches[1].Trim()]=$matches[2].Trim()}}
$taskDbHost=if($taskSettings['DB_HOST']){$taskSettings['DB_HOST']}else{'127.0.0.1'}
$taskDbPort=if($taskSettings['DB_PORT']){[int]$taskSettings['DB_PORT']}else{3306}
if(!(Test-TaskPort $taskDbHost $taskDbPort)) {
  $taskMySqlBase=Join-Path $taskRuntimePath 'mysql-runtime\mysql-8.0.40-winx64'
  $taskMySqlData=Join-Path $taskRuntimePath 'mysql-data'
  if($taskDbHost -ne '127.0.0.1' -or !(Test-Path -LiteralPath (Join-Path $taskMySqlBase 'bin\mysqld.exe')) -or !(Test-Path -LiteralPath $taskMySqlData)) {throw 'MySQL is not running. Start your configured MySQL server first.'}
  $taskDbArgs=@('--no-defaults',("--basedir=`"$taskMySqlBase`""),("--datadir=`"$taskMySqlData`""),'--bind-address=127.0.0.1',("--port=$taskDbPort"),'--mysqlx=0','--console')
  $taskDbProcess=Start-Process -FilePath (Join-Path $taskMySqlBase 'bin\mysqld.exe') -ArgumentList $taskDbArgs -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntimePath 'mysql.log') -RedirectStandardError (Join-Path $taskRuntimePath 'mysql-error.log')
  $taskDbProcess.Id | Set-Content -LiteralPath (Join-Path $taskRuntimePath 'mysql.pid')
  for($taskTry=0;$taskTry -lt 20;$taskTry++){if(Test-TaskPort $taskDbHost $taskDbPort){break};Start-Sleep -Milliseconds 500}
  if(!(Test-TaskPort $taskDbHost $taskDbPort)){throw 'MySQL did not start; inspect work/mysql-error.log.'}
}
if(!(Test-Path -LiteralPath (Join-Path $taskAppPath 'dist\index.html'))) {Push-Location $taskAppPath;try{npm ci;npm run build;if($LASTEXITCODE){throw 'Frontend build failed'}}finally{Pop-Location}}
if(!(Test-TaskPort '127.0.0.1' $Port)) {
  $taskPhp=(Get-Command php -ErrorAction Stop).Source
  $taskPhpArgs=@()
  $taskExtensionDir=Join-Path (Split-Path $taskPhp -Parent) 'ext'
  $taskHasMySql=& $taskPhp -r "echo extension_loaded('pdo_mysql') ? 'yes' : 'no';"
  if($taskHasMySql -ne 'yes') {
    if(!(Test-Path -LiteralPath (Join-Path $taskExtensionDir 'php_pdo_mysql.dll'))){throw 'Enable pdo_mysql in PHP first.'}
    $taskPhpArgs+=@('-d',("extension_dir=`"$taskExtensionDir`""),'-d','extension=php_pdo_mysql.dll')
  }
  $taskPhpArgs+=@('-d','display_errors=0','-S',("127.0.0.1:$Port"),'-t','backend/public','backend/router.php')
  $taskPhpProcess=Start-Process -FilePath $taskPhp -ArgumentList $taskPhpArgs -WorkingDirectory $taskAppPath -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntimePath 'php.log') -RedirectStandardError (Join-Path $taskRuntimePath 'php-error.log')
  $taskPhpProcess.Id | Set-Content -LiteralPath (Join-Path $taskRuntimePath 'php.pid')
}
Write-Host "Zhilian is available at http://127.0.0.1:$Port"
