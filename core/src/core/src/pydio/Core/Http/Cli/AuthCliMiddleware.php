<?php
/*
 * Copyright 2007-2015 Abstrium <contact (at) pydio.com>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Core\Http\Cli;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pydio\Auth\Core\MemorySafe;

use Pydio\Core\Exception\AuthRequiredException;
use Pydio\Core\Exception\LoginException;
use Pydio\Core\Http\Response\SerializableResponseStream;
use Pydio\Core\Http\Server;
use Pydio\Core\Model\Context;
use Pydio\Core\PluginFramework\PluginsService;
use Pydio\Core\Services\AuthService;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Services\RepositoryService;
use Pydio\Core\Services\RolesService;
use Pydio\Core\Services\UsersService;
use Pydio\Core\Utils\ApplicationState;
use Pydio\Core\Utils\TextEncoder;
use Pydio\Core\Utils\Utils;
use Pydio\Log\Core\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Stream;

defined('AJXP_EXEC') or die('Access not allowed');

/**
 * Authenticatino middleware used in Command Line context
 * @package Pydio\Core\Http\Cli
 */
class AuthCliMiddleware
{

    /**
     * @param $options
     * @return \Pydio\Core\Model\UserInterface
     * @throws AuthRequiredException
     */
    protected static function authenticateFromCliParameters($options){

        $optUser = $options["u"];
        $optPass = $options["p"];

        if (!empty($options["p"])) {

            $optPass = $options["p"];

        } else {
            // Consider "u" is a crypted version of u:p
            $optToken = $options["t"];
            $cKey = ConfService::getGlobalConf("AJXP_CLI_SECRET_KEY", "conf");
            if(empty($cKey)) $cKey = "\1CDAFx¨op#";
            $optUser = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($optToken.$cKey), base64_decode($optUser), MCRYPT_MODE_ECB), "\0");
            $env = getenv("AJXP_SAFE_CREDENTIALS");
            if(!empty($env)){
                $array = MemorySafe::getCredentialsFromEncodedString($env);
                if(isSet($array["user"]) && $array["user"] == $optUser){
                    unset($optToken);
                    $optPass = $array["password"];
                }
            }

        }


        if (UsersService::usersEnabled() && !empty($optUser)) {
            $seed = AuthService::generateSeed();
            if ($seed != -1) {
                $optPass = md5(md5($optPass).$seed);
            }
            try{
                $loggedUser = AuthService::logUser($optUser, $optPass, isSet($optToken), false, $seed);
            }catch (LoginException $l){
                throw new AuthRequiredException();
            }

        } else {
            throw new AuthRequiredException();
        }

        return $loggedUser;
    }

    /**
     * @param ServerRequestInterface $requestInterface
     * @param \Psr\Http\Message\ResponseInterface $responseInterface
     * @param callable|null $next
     * @return ResponseInterface
     * @throws AuthRequiredException
     */
    public static function handleRequest(ServerRequestInterface $requestInterface, ResponseInterface $responseInterface, callable $next = null){

        $driverImpl = ConfService::getAuthDriverImpl();
        PluginsService::getInstance(Context::emptyContext())->setPluginUniqueActiveForType("auth", $driverImpl->getName(), $driverImpl);

        /** @var OutputInterface $output */
        $output = $requestInterface->getAttribute("cli-output");
        $options = $requestInterface->getAttribute("cli-options");
        $optRepoId = $options["r"];

        $impersonateUsers = false;
        // TODO 1/ REIMPLEMENT parameter queue: to pass a file with many user names?
        /**
         * if (strpos($optUser, "queue:") === 0) {
        $optUserQueue = substr($optUser, strlen("queue:"));
        $optUser = false;
        //echo("QUEUE : ".$optUserQueue);
        if (is_file($optUserQueue)) {
        $lines = file($optUserQueue);
        if (count($lines) && !empty($lines[0])) {
        $allUsers = explode(",", $lines[0]);
        $optUser = array_shift($allUsers);
        file_put_contents($optUserQueue, implode(",", $allUsers));
        }
        }
        if ($optUser === false) {
        if (is_file($optUserQueue)) {
        unlink($optUserQueue);
        }
        die("No more users inside queue");
        }
         */
        // TODO 2/ REIMPLEMENT DETECT USER PARAMETER BASED ON REPOSITORY PATH OPTION ?
        /*
        if ($optDetectUser != false) {
        $path = $repository->getOption("PATH", true);
        if (strpos($path, "AJXP_USER") !== false) {
        $path = str_replace(
        array("AJXP_INSTALL_PATH", "AJXP_DATA_PATH", "/"),
        array(AJXP_INSTALL_PATH, AJXP_DATA_PATH, DIRECTORY_SEPARATOR),
        $path
        );
        $parts = explode("AJXP_USER", $path);
        if(count($parts) == 1) $parts[1] = "";
        $first = str_replace("\\", "\\\\", $parts[0]);
        $last = str_replace("\\", "\\\\", $parts[1]);
        */
        //if (preg_match("/$first(.*)$last.*/", $optDetectUser, $matches)) {
        // $detectedUser = $matches[1];
        //    }
        //}
        //}


        $loggedUser = self::authenticateFromCliParameters($options);

        $requestInterface = $requestInterface->withAttribute("action", $options["a"]);

        if(UsersService::usersEnabled() && ApplicationState::detectApplicationFirstRun()){

            RolesService::bootSequence();

        }

        // Output directly to STDOUT
        $responseInterface = $responseInterface->withBody(new Stream(STDOUT, "w"));

        $applyCallback = function ($userId, $baseGroup, $index, $total) use ($optRepoId, $requestInterface, $output, $next){

            $actionName = $requestInterface->getAttribute("action");
            $output->writeln("<info>*****************************</info>");
            $output->writeln("<info>Current User is '".$userId."'</info>");
            $output->writeln("<info>*****************************</info>");
            try{
                $user = UsersService::getUserById($userId);

                if(is_array($optRepoId)){
                    $repos = [];
                    foreach($optRepoId as $repoId){
                        $repoObj = UsersService::getRepositoryWithPermission($user, $repoId);
                        $repos[$repoObj->getId()] = $repoObj;
                    }
                }else if($optRepoId === "*"){

                    $repos = UsersService::getRepositoriesForUser($user, false); // Do not include shared
                    // Exclude "ajxp_*" repositories and "inbox"
                    foreach($repos as $id=>$object){
                        if($id === "inbox" || strpos($id, "ajxp_") === 0) unset($repos[$id]);
                    }

                }else{
                    $repoObject = UsersService::getRepositoryWithPermission($user, $optRepoId);
                    $repos = [$repoObject->getId() => $repoObject];
                }

                foreach( $repos as $repoId => $repositoryInterface ){

                    try{
                        $output->writeln("<comment>Applying action '$actionName' on workspace ".$repositoryInterface->getDisplay()." (".$repoId.")</comment>");
                        $subResponse = new Response();
                        $ctx = Context::contextWithObjects($user, $repositoryInterface);
                        $requestInterface = $requestInterface->withAttribute("ctx", $ctx);
                        Logger::updateContext($ctx);
                        TextEncoder::updateContext($ctx);

                        $subResponse = Server::callNextMiddleWareAndRewind(function($middleware){
                            return (is_array($middleware) && $middleware["0"] == "Pydio\\Core\\Http\\Cli\\AuthCliMiddleware" && $middleware[1] == "handleRequest");
                        },
                            $requestInterface,
                            $subResponse,
                            $next
                        );

                        $body = $subResponse->getBody();
                        if($body instanceof SerializableResponseStream){
                            if($requestInterface->getParsedBody()["format"] == "json"){
                                $body->setSerializer(SerializableResponseStream::SERIALIZER_TYPE_JSON, ["pretty" => true]);
                            }else if($requestInterface->getParsedBody()["format"] == "xml"){
                                $body->setSerializer(SerializableResponseStream::SERIALIZER_TYPE_XML, ["pretty" => true]);
                            }else if($body->supportsSerializer(SerializableResponseStream::SERIALIZER_TYPE_CLI)){
                                $body->setSerializer(SerializableResponseStream::SERIALIZER_TYPE_CLI, ["output" => $output]);
                            }
                        }
                        $output->writeln( $body->getContents() );

                    }catch (\Exception $repoEx){

                        $output->writeln("<error>".$repoEx->getMessage()."</error>");

                    }

                }

            }catch (\Exception $userEx){

                $output->writeln("<error>".$userEx->getMessage()."</error>");

            }

        };


        if(!empty($options["i"])){
            if(!is_array($options["i"]) && strpos($options["i"], "*") === false){
                $options["i"] = [$options["i"]];
            }
            $impersonateUsers = $options["i"];
        }

        if ($impersonateUsers !== false && $loggedUser->isAdmin()) {

            if(is_array($impersonateUsers)){
                // Apply to a predefined list of users
                foreach ($impersonateUsers as $index => $impersonateUser) {
                    $applyCallback($impersonateUser, "/", $index, count($impersonateUsers));
                }

            }else if($impersonateUsers === "*"){
                // Apply to all users of current group
                UsersService::browseUsersGroupsWithCallback($loggedUser->getGroupPath(), $applyCallback, false);

            }else if($impersonateUsers === "**/*"){
                // Apply to all users recursively
                UsersService::browseUsersGroupsWithCallback($loggedUser->getGroupPath(), $applyCallback, true);

            }

        }else{

            $applyCallback($loggedUser->getId(), "/", 1, 1);

        }

        return $responseInterface;

    }

}