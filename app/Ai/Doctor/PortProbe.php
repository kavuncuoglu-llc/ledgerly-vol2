<?php

namespace App\Ai\Doctor;

// Is a port answering on any address of this machine other than loopback? Ollama has no authentication, so if it is, anyone who can reach this machine can run inference on it.
class PortProbe
{
    /** @return list<string> the non-loopback addresses on which the port answered */
    public function exposedOn(int $port): array
    {
        $answering = [];

        foreach ($this->addresses() as $address) {
            $socket = @fsockopen($address, $port, $errorCode, $errorMessage, 0.3);

            if ($socket === false) {
                continue;
            }

            fclose($socket);

            $answering[] = $address;
        }

        return $answering;
    }

    /** @return list<string> */
    protected function addresses(): array
    {
        $addresses = [];

        foreach (net_get_interfaces() ?: [] as $interface) {
            if (! ($interface['up'] ?? false)) {
                continue;
            }

            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = $unicast['address'] ?? '';

                if (($unicast['family'] ?? null) !== AF_INET) {
                    continue;
                }

                if (str_starts_with($address, '127.')) {
                    continue;
                }

                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
