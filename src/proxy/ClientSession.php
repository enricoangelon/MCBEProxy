<?php


namespace proxy;


use raklib\protocol\ConnectedPing;
use raklib\protocol\ConnectedPong;
use raklib\protocol\ConnectionRequest;
use raklib\protocol\ConnectionRequestAccepted;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\PacketSerializer;
use raklib\utils\InternetAddress;

class ClientSession extends NetworkSession
{
    private ProxyServer $proxyServer;
    private InternetAddress $clientAddress;

    private ?ConnectedClientHandler $connectedClientHandler = null;

    public function __construct(ProxyServer $server, InternetAddress $address)
    {
        parent::__construct();
        $this->clientAddress = $address;
        $this->proxyServer = $server;
    }

    public function process(float $currentTime): void {
        if (($handler = $this->connectedClientHandler) != null) {
            if (($session = $handler->serverSession) != null) {
                $session->tick(microtime(true));
            }
        }
    }

    public function handleDatagram(string $buffer): bool {
        $pid = ord($buffer[0]);
        if ($pid == MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_1) {
            $this->handleOpenConnectionRequestOne($buffer);
            return true;
        } elseif ($pid == MessageIdentifiers::ID_OPEN_CONNECTION_REQUEST_2) {
            $this->handleOpenConnectionRequestTwo($buffer);
            return true;
        }
        return false;
    }

    public function handleEncapsulated(EncapsulatedPacket $packet): void {
        $pid = ord($packet->buffer[0]);
        switch ($pid) {
            case MessageIdentifiers::ID_CONNECTED_PING:
                $connectedPing = new ConnectedPing();
                $connectedPing->decode(new PacketSerializer($packet->buffer));

                $connectedPong = new ConnectedPong();
                $connectedPong->sendPingTime = $connectedPing->sendPingTime;
                $connectedPong->sendPongTime = time();
                $this->sendEncapsulatedBuffer(ProxyServer::encodePacket($connectedPong));
                break;
            case MessageIdentifiers::ID_CONNECTION_REQUEST:
                $connReq = new ConnectionRequest();
                $connReq->decode(new PacketSerializer($packet->buffer));

                $connReqAccepted = new ConnectionRequestAccepted();
                $connReqAccepted->sendPingTime = $connReq->sendPingTime;
                $connReqAccepted->sendPongTime = time();
                $connReqAccepted->address = $this->clientAddress;
                $this->sendEncapsulatedBuffer(ProxyServer::encodePacket($connReqAccepted));
                break;
            case MessageIdentifiers::ID_NEW_INCOMING_CONNECTION:
                $this->proxyServer->getLogger()->info("Connection with {$this->clientAddress->toString()} successfully established!");
                $this->connectedClientHandler = new ConnectedClientHandler($this);
                break;
            case MessageIdentifiers::ID_DISCONNECTION_NOTIFICATION:
                $this->proxyServer->deleteSession($this);
                break;
            case ProxyServer::MINECRAFT_HEADER:
                if (($handler = $this->connectedClientHandler) != null) {
                    $handler->handleMinecraft($packet);
                }
                break;
            default:
                $this->proxyServer->getLogger()->info("Not implemented!");
        }
    }

    private function handleOpenConnectionRequestOne(string $buffer): void {
        $reqOne = new OpenConnectionRequest1();
        $reqOne->decode(new PacketSerializer($buffer));

        $replyOne = new OpenConnectionReply1();
        $replyOne->serverID = $this->proxyServer->getServerID();
        $replyOne->mtuSize = $reqOne->mtuSize + 28;
        $this->sendBuffer(ProxyServer::encodePacket($replyOne));
    }

    private function handleOpenConnectionRequestTwo(string $buffer): void {
        $reqTwo = new OpenConnectionRequest2();
        $reqTwo->decode(new PacketSerializer($buffer));

        $replyTwo = new OpenConnectionReply2();
        $replyTwo->mtuSize = $reqTwo->mtuSize;

        $this->mtuSize = $reqTwo->mtuSize;

        $replyTwo->clientAddress = $this->clientAddress;
        $replyTwo->serverID = $this->proxyServer->getServerID();
        $this->sendBuffer(ProxyServer::encodePacket($replyTwo));
    }

    public function isConnected(): bool {
        return $this->connectedClientHandler != null;
    }

    public function getConnectedClient(): ?ConnectedClientHandler {
        return $this->connectedClientHandler;
    }

    public function getClientAddress(): InternetAddress {
        return $this->clientAddress;
    }

    public function getProxy(): ProxyServer {
        return $this->proxyServer;
    }

    public function sendBuffer(string $buffer): void {
        $this->proxyServer->sendBuffer($buffer, $this->clientAddress);
    }
}