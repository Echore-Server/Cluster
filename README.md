# Cluster

サーバー同士を簡単に連携できるようにするプラグイン

## 使用方法

1. どこかの場所にクラスター情報を記載した json ファイルを作成する [jsonのフォーマット](./CLUSTER_INFO.md)
2. `plugin_data/Cluster/path.txt` にさきほどの json ファイルへのパスを入れる
3. `plugin_data/Cluster/cluster.txt` にこのサーバーのクラスター識別子を入れる (`identifier`)
4. TADA!!

## 拡張

> [!NOTE]
>
> このプラグインは拡張用です。<br>
> 単体ではただ接続するだけのため、様々な機能を他のプラグインから追加する必要があります。<br>

### パケットの追加

1. [ClusterPacket](src/ipc/packet/ClusterPacket.php) を継承するクラスを作成
2. 適切なIDを設定 (`getId`, 被らないように `<PluginName>:<Identifier>` のようなIDをお勧めします。)
3. [ClusterIPCStartupEvent](src/event/ClusterIPCStartupEvent.php) をリッスンして [ClusterIPC](src/ipc/ClusterIPC.php)
   を取得し、[ClusterPacketPool](src/ipc/packet/ClusterPacketPool.php) にパケットを登録<br>

### パケットハンドリング

[ClusterPacketReceiveEvent](src/event/ClusterPacketReceiveEvent.php) をリッスンして処理
